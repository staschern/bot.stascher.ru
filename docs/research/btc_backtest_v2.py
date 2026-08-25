#!/usr/bin/env python3
"""
BTC/USDT hourly backtest — 7 strategies, Jan 2022 – Jul 2025.
Data: calibrated synthetic GBM anchored to known BTC price history.
Anchors: Jan-22 $47k → FTX-crash $15.5k → recovery → ATH Mar-24 $73k → 2025 $100k+
Annual volatility σ ≈ 75 %  (BTC actual ~70-90 %)
Fee: 0.075 % per side (taker with BNB).
Long-only, no leverage, no shorting.
"""
import numpy as np, pandas as pd, json, math
from datetime import datetime, timezone, timedelta

np.random.seed(42)

# ─── Synthetic BTC generator ─────────────────────────────────────────────────

ANCHORS = [                         # (date, price)  — known BTC checkpoints
    ("2022-01-01",  47000),
    ("2022-04-01",  45000),
    ("2022-06-15",  20000),         # LUNA crash
    ("2022-11-10",  15800),         # FTX collapse
    ("2023-01-01",  16500),
    ("2023-04-01",  28000),
    ("2023-07-01",  30500),
    ("2023-10-01",  27000),
    ("2023-11-01",  34000),
    ("2024-01-01",  42000),
    ("2024-03-14",  73700),         # ATH Mar-2024
    ("2024-06-01",  67000),
    ("2024-08-01",  65000),
    ("2024-09-01",  57000),
    ("2024-11-01",  70000),
    ("2024-12-01",  96000),
    ("2024-12-15", 107000),         # ~ATH Dec-2024
    ("2025-01-01",  96000),
    ("2025-02-01",  96000),
    ("2025-03-01",  85000),
    ("2025-04-01",  82000),
    ("2025-05-01",  94000),
    ("2025-06-01", 103000),
    ("2025-07-01", 107000),
]

def parse_anchor(s):
    return datetime.strptime(s, "%Y-%m-%d").replace(tzinfo=timezone.utc)

def generate_btc_hourly():
    vol_hourly = 0.75 / math.sqrt(8760)  # σ=75% annual → per-hour

    dates, prices = [], []
    anchors = [(parse_anchor(d), p) for d, p in ANCHORS]

    for i in range(len(anchors) - 1):
        t0, p0 = anchors[i]
        t1, p1 = anchors[i + 1]
        hours = int((t1 - t0).total_seconds() / 3600)
        # drift needed to hit p1 from p0 in `hours` steps
        # p1 = p0 * exp(mu*h + sigma*sqrt(h)*Z) ≈ p0 * exp(mu*h)
        mu = math.log(p1 / p0) / hours  # per-hour drift
        # GBM path
        z = np.random.normal(0, 1, hours)
        log_ret = mu + vol_hourly * z
        path = p0 * np.exp(np.cumsum(log_ret))
        ts_range = [t0 + timedelta(hours=k+1) for k in range(hours)]
        dates.extend(ts_range)
        prices.extend(path.tolist())

    # Build OHLCV from close prices
    closes = np.array(prices)
    n = len(closes)
    # simulate open/high/low
    opens  = np.roll(closes, 1); opens[0] = closes[0]
    intra  = closes * vol_hourly * np.abs(np.random.normal(1, 0.5, n)) * 2
    highs  = np.maximum(closes, opens) + intra * 0.6
    lows   = np.minimum(closes, opens) - intra * 0.6
    vols   = np.random.lognormal(10, 0.5, n)

    df = pd.DataFrame({
        "open": opens, "high": highs, "low": lows,
        "close": closes, "vol": vols
    }, index=pd.DatetimeIndex(dates, tz="UTC", name="ts"))
    return df

# ─── Indicators ───────────────────────────────────────────────────────────────

def ema(s, n): return s.ewm(span=n, adjust=False).mean()
def sma(s, n): return s.rolling(n).mean()
def atr(df, n=14):
    tr = pd.concat([df["high"]-df["low"],
                    (df["high"]-df["close"].shift()).abs(),
                    (df["low"] -df["close"].shift()).abs()], axis=1).max(axis=1)
    return tr.ewm(alpha=1/n, adjust=False).mean()

def rsi(s, n=14):
    d = s.diff()
    g = d.clip(lower=0).ewm(alpha=1/n, adjust=False).mean()
    l = (-d.clip(upper=0)).ewm(alpha=1/n, adjust=False).mean()
    return 100 - 100 / (1 + g / l.replace(0, 1e-9))

# ─── Backtest engine ─────────────────────────────────────────────────────────

FEE = 0.00075

def backtest(df, signal_fn, label):
    sig  = signal_fn(df).reindex(df.index).fillna(0).values
    price = df["close"].values
    equity = 1.0; pos = 0; ep = 0.0
    trades = []; eq_curve = []

    for i in range(1, len(price)):
        s = sig[i-1]  # prev bar signal → execute at open of this bar
        p = price[i]
        if pos == 0 and s == 1:
            ep = p * (1 + FEE); pos = 1
        elif pos == 1 and s == -1:
            ret = p*(1-FEE)/ep - 1
            equity *= (1+ret); trades.append(ret); pos = 0
        eq_val = equity if pos == 0 else equity * p*(1-FEE)/ep
        eq_curve.append(eq_val)

    if pos == 1:
        ret = price[-1]*(1-FEE)/ep - 1
        equity *= (1+ret); trades.append(ret)

    eq_arr = np.array(eq_curve or [1.0])
    tr = np.array(trades) if trades else np.array([0.0])
    n_days = (df.index[-1] - df.index[0]).days or 1
    n_yr   = n_days / 365.25
    cagr   = equity**(1/n_yr) - 1
    monthly = (1+cagr)**(1/12) - 1
    peak = np.maximum.accumulate(eq_arr)
    max_dd = ((eq_arr-peak)/peak).min()
    hr = np.diff(eq_arr)/(eq_arr[:-1]+1e-12)
    sharpe = hr.mean()/(hr.std()+1e-9)*math.sqrt(8760)
    wins = tr[tr>0]; loss = tr[tr<=0]
    pf = -wins.sum()/loss.sum() if loss.sum()<0 else float("inf")
    return dict(
        strategy       = label,
        n_trades       = len(tr),
        win_rate       = round(100*len(wins)/max(len(tr),1), 1),
        avg_win        = round(100*wins.mean(), 2) if len(wins) else 0,
        avg_loss       = round(100*loss.mean(), 2) if len(loss) else 0,
        profit_factor  = round(pf, 2),
        total_ret      = round(100*(equity-1), 1),
        monthly_ret    = round(100*monthly, 2),
        cagr           = round(100*cagr, 1),
        max_dd         = round(100*max_dd, 1),
        sharpe         = round(sharpe, 2),
    )

# ─── Signal generators ───────────────────────────────────────────────────────

def hold_signal(sig_raw):
    """Convert cross/event signals to hold-until-opposite series."""
    last = 0; out = []
    for v in sig_raw.values:
        if v == 1:  last = 1
        elif v ==-1: last = -1
        out.append(last)
    return pd.Series(out, index=sig_raw.index)

def s1(df):
    """EMA 50/200 crossover."""
    f = ema(df["close"], 50); s = ema(df["close"], 200)
    ev = pd.Series(0, index=df.index)
    ev[(f>s)&(f.shift()<=s.shift())] = 1
    ev[(f<s)&(f.shift()>=s.shift())] = -1
    return hold_signal(ev)

def s2(df):
    """EMA 21/55 + RSI ≥ 45 entry filter, RSI < 40 exit."""
    f = ema(df["close"],21); sl = ema(df["close"],55); r = rsi(df["close"],14)
    ev = pd.Series(0, index=df.index)
    ev[(f>sl)&(f.shift()<=sl.shift())&(r>=45)] = 1
    ev[(f<sl)&(f.shift()>=sl.shift())] = -1
    ev[r<40] = -1
    return hold_signal(ev)

def s3(df):
    """MACD (12/26/9) crossover."""
    m = ema(df["close"],12)-ema(df["close"],26); sig = ema(m,9)
    ev = pd.Series(0, index=df.index)
    ev[(m>sig)&(m.shift()<=sig.shift())] = 1
    ev[(m<sig)&(m.shift()>=sig.shift())] = -1
    return hold_signal(ev)

def s4(df):
    """RSI(14) mean reversion: buy<30, sell>70."""
    r = rsi(df["close"],14)
    ev = pd.Series(0, index=df.index)
    ev[r<30] = 1; ev[r>70] = -1
    return hold_signal(ev)

def s5(df):
    """Bollinger Bands (20h,2σ) breakout: buy above upper, sell below SMA."""
    m = sma(df["close"],20); std = df["close"].rolling(20).std()
    upper = m+2*std
    ev = pd.Series(0, index=df.index)
    ev[df["close"]>upper] = 1
    ev[df["close"]<m] = -1
    return hold_signal(ev)

def s6(df):
    """Triple EMA (8/21/55) + ATR filter."""
    e8=ema(df["close"],8); e21=ema(df["close"],21); e55=ema(df["close"],55)
    a = atr(df,14)
    ev = pd.Series(0, index=df.index)
    up = (e8>e21)&(e21>e55)&(df["close"]>e55+0.3*a)
    dn = (e8<e21)&(e21<e55)
    ev[up] = 1; ev[dn] = -1
    return hold_signal(ev)

def s7(df):
    """Weekly high breakout (168h) + EMA200 trend filter; exit below EMA50."""
    wh = df["high"].rolling(168).max().shift(1)
    e200 = ema(df["close"],200); e50 = ema(df["close"],50)
    ev = pd.Series(0, index=df.index)
    ev[(df["close"]>wh)&(df["close"]>e200)] = 1
    ev[df["close"]<e50] = -1
    return hold_signal(ev)

# ─── Main ─────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    print("Generating calibrated BTC/USDT 1h data (2022-01-01 → 2025-07-01)...")
    df = generate_btc_hourly()
    print(f"  {len(df)} hourly bars  |  ${df['close'].iloc[0]:,.0f} → ${df['close'].iloc[-1]:,.0f}")
    print(f"  min ${df['close'].min():,.0f}  max ${df['close'].max():,.0f}")

    strats = [
        ("S1: EMA 50/200 Crossover",             s1),
        ("S2: EMA 21/55 + RSI Filter",            s2),
        ("S3: MACD (12/26/9)",                    s3),
        ("S4: RSI Mean Reversion (30/70)",         s4),
        ("S5: Bollinger Bands Breakout (20h,2σ)", s5),
        ("S6: Triple EMA + ATR (8/21/55)",         s6),
        ("S7: Weekly High Breakout + EMA200",      s7),
    ]

    results = []
    for label, fn in strats:
        r = backtest(df, fn, label)
        results.append(r)
        print(f"  {label:<44} monthly {r['monthly_ret']:>+7.2f}%  "
              f"CAGR {r['cagr']:>+6.1f}%  DD {r['max_dd']:>5.1f}%  "
              f"trades {r['n_trades']:>4}  WR {r['win_rate']:>5.1f}%")

    # Buy & Hold benchmark
    bh = df["close"].iloc[-1]/df["close"].iloc[0]
    n_days = (df.index[-1]-df.index[0]).days
    bh_cagr = bh**(365.25/n_days)-1
    bh_m = (1+bh_cagr)**(1/12)-1
    c = df["close"].values
    bh_dd = ((c - np.maximum.accumulate(c))/np.maximum.accumulate(c)).min()
    bh_r = dict(strategy="BUY & HOLD (benchmark)", n_trades=1, win_rate=100,
                avg_win=round(100*(bh-1),1), avg_loss=0, profit_factor=999,
                total_ret=round(100*(bh-1),1), monthly_ret=round(100*bh_m,2),
                cagr=round(100*bh_cagr,1), max_dd=round(100*bh_dd,1), sharpe="—")
    results.append(bh_r)

    results.sort(key=lambda x: float(x["monthly_ret"]) if x["monthly_ret"]!="—" else -999, reverse=True)

    print("\n" + "="*100)
    HDR = f"{'#':<3} {'Strategy':<44} {'Monthly%':>9} {'CAGR%':>7} {'Total%':>8} {'MaxDD%':>7} {'Sharpe':>7} {'Trades':>7} {'WR%':>6} {'PF':>6}"
    print(HDR); print("="*100)
    for i,r in enumerate(results,1):
        print(f"{i:<3} {r['strategy']:<44} {r['monthly_ret']:>9} {r['cagr']:>7} "
              f"{r['total_ret']:>8} {r['max_dd']:>7} {str(r['sharpe']):>7} "
              f"{r['n_trades']:>7} {r['win_rate']:>6} {str(r['profit_factor']):>6}")

    out = {"note": "Calibrated synthetic GBM data anchored to known BTC price history",
           "period": "2022-01-01 — 2025-07-01", "candles": len(df),
           "fee_per_side_pct": 0.075, "leverage": 1, "results": results}
    with open("/tmp/btc_backtest_results.json","w") as f:
        json.dump(out, f, indent=2)
    print("\nSaved: /tmp/btc_backtest_results.json")
