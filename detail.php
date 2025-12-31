<?php
$conn = new mysqli("localhost", "ifupnvjt_root", "KitaBisa", "ifupnvjt_db_crypto");
if ($conn->connect_error) die("Koneksi gagal: " . $conn->connect_error);
$pair = $_GET['pair'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Detail Pair <?= htmlspecialchars($pair) ?></title>
  <link rel="stylesheet" href="style.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3"></script>
  <script src="https://cdn.jsdelivr.net/npm/luxon@3"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1"></script>
  <style>
    .page-title{margin:16px auto 8px; max-width:1100px; text-align:center;}
    .top-buttons{max-width:1100px; margin:0 auto 12px; display:flex; gap:10px; align-items:center; justify-content:center;}
    .legend-controls{max-width:1100px;margin:8px auto; display:flex; gap:8px; flex-wrap:wrap; justify-content:center;}
    .legend-btn{padding:8px 12px; border:1px solid #d7dee9; border-radius:8px; background:#fff; cursor:pointer; font-weight:600; transition:.15s}
    .legend-btn:hover{box-shadow:0 2px 8px rgba(16,24,40,.08); transform:translateY(-1px)}
    .legend-btn.active{background:#0ea5e9; color:#fff; border-color:#0ea5e9}
    .legend-btn.success{background:#22c55e; color:#fff; border-color:#22c55e}
    .btn-back{padding:8px 12px; border-radius:8px; background:#0f2742; color:#fff; text-decoration:none}

    .chart-container{max-width:1100px; margin:10px auto; background:#fff; border-radius:12px; box-shadow:0 1px 6px rgba(0,0,0,.08); padding:12px}
    .chart-container canvas{width:100% !important;}

    /* Tinggi panel supaya tidak gepeng */
    #chartHarga{height:340px !important}
    #chartRSI{height:300px !important}
    #chartMACD{height:300px !important}

    .toolbar{max-width:1100px;margin:8px auto 0;display:flex;gap:8px;justify-content:flex-end}
    #fallbackNote{display:none; margin-bottom:6px; font-size:13px; color:#92400e; background:#fef3c7; border:1px solid #fcd34d; padding:6px 10px; border-radius:8px;}

    #modalOverlay{position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:9999}
    #modal{background:#fff; width:min(900px,90vw); max-height:80vh; overflow:auto; border-radius:12px; padding:16px}
    .close-btn{float:right; padding:6px 10px; border:1px solid #ddd; border-radius:8px; background:#fff; cursor:pointer}
    .data-table{width:100%; border-collapse:collapse}
    .data-table th,.data-table td{border:1px solid #e6e6e6; padding:8px 10px; text-align:center}
  </style>
</head>
<body>
  <h2 class="page-title">Detail Pair: <?= htmlspecialchars($pair) ?></h2>
  <div class="top-buttons">
    <a href="index.php" class="btn-back">← Kembali</a>
    <button type="button" id="btn-signal-popup" class="legend-btn success">Lihat Daftar Sinyal</button>
  </div>

  <div class="legend-controls">
    <button type="button" class="legend-btn" id="btn-bb">Bollinger Bands</button>
    <button type="button" class="legend-btn" id="btn-rsi">RSI</button>
    <button type="button" class="legend-btn" id="btn-macd">MACD</button>
  </div>

  <div class="toolbar" id="toolbar">
    <button type="button" class="legend-btn" data-range="6h">6H</button>
    <button type="button" class="legend-btn" data-range="24h">24H</button>
    <button type="button" class="legend-btn" data-range="3d">3D</button>
    <button type="button" class="legend-btn" id="btnResetRange">Reset Range</button>
  </div>

  <div class="chart-container" id="chartHargaBox">
    <div id="fallbackNote">Data 7 hari terakhir kosong. Menampilkan 288 baris terakhir.</div>
    <canvas id="chartHarga"></canvas>
  </div>
  <div class="chart-container" id="chartRSIBox" style="display:none;"><canvas id="chartRSI"></canvas></div>
  <div class="chart-container" id="chartMACDBox" style="display:none;"><canvas id="chartMACD"></canvas></div>

<?php
if ($pair) {
  // data harga
  $rows=[];
  $sql7="SELECT id,last_price,high_price,low_price,buy_price,sell_price,volume,server_time
         FROM `$pair`
         WHERE server_time >= UNIX_TIMESTAMP() - (7*24*60*60)
         ORDER BY server_time ASC";
  if($q=$conn->query($sql7)) while($r=$q->fetch_assoc()) $rows[]=$r;

  $usedFallback=false;
  if(count($rows)===0){
    $usedFallback=true;
    $q2=$conn->query("SELECT id,last_price,high_price,low_price,buy_price,sell_price,volume,server_time
                      FROM `$pair` ORDER BY server_time DESC, id DESC LIMIT 288");
    $rows=[];
    if($q2) while($r=$q2->fetch_assoc()) $rows[]=$r;
    $rows=array_reverse($rows);
  }

  $pricePoints=[]; $timeEpochs=[];
  foreach($rows as $r){
    $ms=((int)$r['server_time'])*1000;
    $pricePoints[]=['x'=>$ms,'y'=>(float)$r['last_price']];
    $timeEpochs[]=(int)$r['server_time'];
  }

  // sinyal (BUY & SELL) — bawa epoch agar presisi mapping
  $signals=[];
  $pairEsc=$conn->real_escape_string($pair);
  $s=$conn->query("
    SELECT signal_type, price, reason, confidence, created_at, UNIX_TIMESTAMP(created_at) AS ts
    FROM signals
    WHERE pair='$pairEsc'
    ORDER BY created_at ASC
    LIMIT 800
  ");
  if($s) while($r=$s->fetch_assoc()) $signals[]=$r;

  echo '<script>
    window.APP_DATA = {
      pair: '.json_encode($pair).',
      priceSeries: '.json_encode($pricePoints).',
      timeEpochs: '.json_encode($timeEpochs).',
      signals: '.json_encode($signals).',
      usedFallback: '.($usedFallback?'true':'false').'
    };
  </script>';

  // (optional) tabel riwayat sederhana
  echo '<div class="chart-container" style="margin-top:16px;">';
  echo '<h3>Riwayat Harga '.htmlspecialchars($pair).'</h3>';
  echo '<table class="data-table"><thead><tr>
        <th>ID</th><th>Last</th><th>High</th><th>Low</th>
        <th>Buy</th><th>Sell</th><th>Volume</th><th>Waktu</th>
        </tr></thead><tbody>';
  foreach(array_reverse($rows) as $r){
    echo '<tr>
      <td>'.$r['id'].'</td>
      <td>'.$r['last_price'].'</td>
      <td>'.$r['high_price'].'</td>
      <td>'.$r['low_price'].'</td>
      <td>'.$r['buy_price'].'</td>
      <td>'.$r['sell_price'].'</td>
      <td>'.$r['volume'].'</td>
      <td>'.date('d-m-Y H:i:s',$r['server_time']).'</td>
    </tr>';
  }
  echo '</tbody></table></div>';
}
$conn->close();
?>

  <!-- Modal -->
  <div id="modalOverlay">
    <div id="modal">
      <button type="button" class="close-btn" id="closeModal">Tutup</button>
      <h3>Daftar Sinyal (<?= htmlspecialchars($pair) ?>)</h3>
      <table id="signalTable" class="data-table">
        <thead><tr><th>Tipe</th><th>Waktu</th><th>Harga</th><th>Reason</th><th>Conf</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

<script>
if (window['chartjs-plugin-annotation']) Chart.register(window['chartjs-plugin-annotation']);

/* ===== Helper indikator ===== */
const ema=(a,p)=>{if(a.length<p)return Array(a.length).fill(null);const k=2/(p+1);let e=a.slice(0,p).reduce((x,y)=>x+y,0)/p;const r=Array(p-1).fill(null);r.push(e);for(let i=p;i<a.length;i++){e=a[i]*k+e*(1-k);r.push(e);}return r;};
const calcRSI=(p,per=14)=>{if(p.length<per+1)return Array(p.length).fill(null);let g=[],l=[];for(let i=1;i<p.length;i++){const c=p[i]-p[i-1];g.push(c>0?c:0);l.push(c<0?Math.abs(c):0);}let avgG=g.slice(0,per).reduce((a,b)=>a+b,0)/per,avgL=l.slice(0,per).reduce((a,b)=>a+b,0)/per;const out=Array(per).fill(null);for(let i=per;i<p.length;i++){avgG=((avgG*(per-1))+(g[i]||0))/per;avgL=((avgL*(per-1))+(l[i]||0))/per;out.push(avgL===0?100:100-(100/(1+(avgG/avgL))));}return out;};
const calcMACD=(p)=>{const e12=ema(p,12),e26=ema(p,26);const macd=p.map((_,i)=>(e12[i]!=null&&e26[i]!=null)?e12[i]-e26[i]:null);const clean=macd.filter(v=>v!==null);const sigRaw=clean.length>=9?ema(clean,9):Array(macd.length).fill(null);const signal=Array(macd.length-sigRaw.length).fill(null).concat(sigRaw);return{macd,signal};};
const calcBB=(p,per=20,k=2)=>{const mb=[],ub=[],lb=[];for(let i=0;i<p.length;i++){if(i<per-1){mb.push(null);ub.push(null);lb.push(null);continue;}const s=p.slice(i-per+1,i+1);const m=s.reduce((a,b)=>a+b,0)/per;const sd=Math.sqrt(s.reduce((a,b)=>a+Math.pow(b-m,2),0)/per);mb.push(m);ub.push(m+k*sd);lb.push(m-k*sd);}return{mb,ub,lb};};

/* ===== Data dari PHP ===== */
const APP     = window.APP_DATA || {};
const series  = Array.isArray(APP.priceSeries) ? APP.priceSeries : [];
const epochs  = Array.isArray(APP.timeEpochs)  ? APP.timeEpochs  : [];
const signals = Array.isArray(APP.signals)     ? APP.signals     : [];

if (!series.length && APP.usedFallback) {
  const note = document.getElementById('fallbackNote');
  if (note) note.style.display = 'block';
}

/* ===== Skala waktu ===== */
const canUseTime = !!Chart.registry.getScale('time');
const timeScale  = canUseTime ? { type:'time', time:{ tooltipFormat:'DDD dd LLL yyyy HH:mm', unit:'hour' }, ticks:{ source:'auto' } }
                              : { type:'category' };

/* ===== Hitung indikator untuk panel & BB di harga ===== */
const pricesOnly = series.map(p=>+p.y || 0);
const rsiArr  = calcRSI(pricesOnly,14);
const macdObj = calcMACD(pricesOnly);
const bbObj   = calcBB(pricesOnly,20,2);

const makeXY = (arr)=> canUseTime ? series.map((pt,i)=>({x:pt.x,y:arr[i]})) : arr.map((v,i)=>({x:i,y:v}));

const hargaData = canUseTime ? series : series.map((pt,i)=>({x:i,y:pt.y}));
const rsiData   = makeXY(rsiArr);
const macdData  = makeXY(macdObj.macd);
const sigData   = makeXY(macdObj.signal);
const bbUpper   = makeXY(bbObj.ub);
const bbMid     = makeXY(bbObj.mb);
const bbLower   = makeXY(bbObj.lb);

/* ===== Chart Harga (dengan BB + marker BUY/SELL) ===== */
const chartHarga = new Chart(document.getElementById('chartHarga').getContext('2d'),{
  type:'line',
  data:{datasets:[
    {label:'Harga',data:hargaData,borderColor:'#2563eb',borderWidth:1.8,pointRadius:0,tension:0.15},
    {label:'BB Upper',data:bbUpper,borderColor:'rgba(100,116,139,0.85)',borderDash:[6,6],pointRadius:0,tension:0.15,hidden:true},
    {label:'BB Lower',data:bbLower,borderColor:'rgba(100,116,139,0.85)',borderDash:[6,6],pointRadius:0,tension:0.15,hidden:true},
    {label:'BB Mid',  data:bbMid,  borderColor:'#7c3aed',borderWidth:1,pointRadius:0,tension:0.15,hidden:true},
    {label:'BUY', data:[], showLine:false, pointStyle:'triangle', pointRotation:0,   pointRadius:8,  pointHitRadius:16, pointBackgroundColor:'rgba(16,185,129,1)', pointBorderColor:'rgba(5,150,105,1)', borderColor:'transparent'},
    {label:'SELL',data:[], showLine:false, pointStyle:'triangle', pointRotation:180, pointRadius:8,  pointHitRadius:16, pointBackgroundColor:'rgba(239,68,68,1)',  pointBorderColor:'rgba(185,28,28,1)', borderColor:'transparent'}
  ]},
  options:{
    responsive:true, maintainAspectRatio:false, parsing:false, spanGaps:true,
    plugins:{
      legend:{display:false},
      tooltip:{callbacks:{label:(ctx)=>{
        if(ctx.dataset.label==='BUY'){
          const p=_buyPts[ctx.dataIndex];
          return p ? `BUY: ${p.reason} (conf=${p.conf})` : 'BUY';
        }
        if(ctx.dataset.label==='SELL'){
          const p=_sellPts[ctx.dataIndex];
          return p ? `SELL: ${p.reason} (conf=${p.conf})` : 'SELL';
        }
        return `${ctx.dataset.label}: ${Number(ctx.parsed.y).toLocaleString('id-ID')}`;
      }}}
    },
    scales:{ x:timeScale, y:{title:{display:true,text:'Harga (IDR)'}, ticks:{maxTicksLimit:7, callback:(v)=>Number(v).toLocaleString('id-ID')}, grid:{color:'rgba(0,0,0,.06)'}} }
  }
});

/* ===== RSI autoscale ===== */
const rsiClean = rsiArr.filter(v=>v!=null && !Number.isNaN(v));
let rMin = rsiClean.length ? Math.min(...rsiClean) : 30;
let rMax = rsiClean.length ? Math.max(...rsiClean) : 70;
rMin = Math.max(0, Math.floor(Math.min(25, rMin) - 10));
rMax = Math.min(100,Math.ceil(Math.max(75, rMax) + 10));
if (rMax - rMin < 40){ const mid=(rMax+rMin)/2; rMin=Math.max(0,Math.floor(mid-20)); rMax=Math.min(100,Math.ceil(mid+20)); }

// === Chart RSI ===
const chartRSI = new Chart(document.getElementById('chartRSI').getContext('2d'),{
  type:'line',
  data:{datasets:[{label:'RSI',data:rsiData,borderColor:'#ef4444',borderWidth:1.6,pointRadius:0,pointHitRadius:10,tension:0.15}]},
  options:{
    parsing:false, spanGaps:true,
    interaction: { mode: 'index', intersect: false, axis: 'x' },
    plugins:{
      decimation:{enabled:true, algorithm:'lttb', samples:700},
      legend:{display:false},
      tooltip:{
        enabled:true,
        callbacks:{
          title: (items)=> {
            const x = items[0].parsed.x; // millis
            return luxon.DateTime.fromMillis(x).setLocale('id').toFormat("dd LLL yyyy HH:mm");
          },
          label: (ctx)=> `RSI: ${Number(ctx.parsed.y).toFixed(2)}`
        }
      },
      annotation: window['chartjs-plugin-annotation'] ? {
        annotations:{
          line70:{type:'line',yMin:70,yMax:70,borderColor:'#22c55e',borderWidth:1},
          line30:{type:'line',yMin:30,yMax:30,borderColor:'#ef4444',borderWidth:1}
        }
      } : undefined
    },
    scales:{ x: timeScale, y:{min:rMin,max:rMax, title:{display:true,text:'RSI'}, ticks:{maxTicksLimit:6,font:{size:10}}, grid:{color:'rgba(0,0,0,0.06)'}} }
  }
});

/* ===== MACD autoscale simetris 0 ===== */
const macAll=[...macdObj.macd,...macdObj.signal].filter(v=>v!=null && !Number.isNaN(v));
let aMax=macAll.length?Math.max(...macAll.map(v=>Math.abs(v))):1; if(aMax<1) aMax=1;
let pad=aMax*0.4, macMin=-(aMax+pad), macMax=aMax+pad; if(macMax-macMin<2){ macMin-=1; macMax+=1; }

// === Chart MACD ===
const chartMACD = new Chart(document.getElementById('chartMACD').getContext('2d'),{
  type:'line',
  data:{datasets:[
    {label:'MACD',data:macdData,borderColor:'#16a34a',borderWidth:1.6,pointRadius:0,pointHitRadius:10,tension:0.15},
    {label:'Signal',data:sigData,borderColor:'#fb923c',borderDash:[4,4],borderWidth:1,pointRadius:0,pointHitRadius:10,tension:0.15}
  ]},
  options:{
    parsing:false, spanGaps:true,
    interaction: { mode: 'index', intersect: false, axis: 'x' },
    plugins:{
      decimation:{enabled:true, algorithm:'lttb', samples:700},
      legend:{display:false},
      tooltip:{
        enabled:true,
        callbacks:{
          title: (items)=> {
            const x = items[0].parsed.x;
            return luxon.DateTime.fromMillis(x).setLocale('id').toFormat("dd LLL yyyy HH:mm");
          },
          label: (ctx)=> `${ctx.dataset.label}: ${Number(ctx.parsed.y).toLocaleString('id-ID', {maximumFractionDigits: 6})}`
        }
      },
      annotation: window['chartjs-plugin-annotation'] ? {
        annotations:{ zero:{type:'line', yMin:0, yMax:0, borderColor:'rgba(0,0,0,0.25)', borderWidth:1 } }
      } : undefined
    },
    scales:{
      x: timeScale,
      y:{min:macMin,max:macMax, title:{display:true,text:'MACD'},
         ticks:{maxTicksLimit:6,font:{size:10},callback:(v)=> Number(v).toLocaleString('id-ID',{maximumFractionDigits:6})},
         grid:{color:'rgba(0,0,0,0.06)'}}
    }
  }
});

/* ===== Range helper (tanpa zoom) ===== */
function setXRangeAll(minX,maxX){ [chartHarga,chartRSI,chartMACD].forEach(ch=>{ ch.options.scales.x.min=minX; ch.options.scales.x.max=maxX; ch.update('none'); }); }
function applyRange(key){
  if(!series.length) return;
  if(canUseTime){
    const last=series[series.length-1].x, H=3600*1000, D=24*H;
    let minX=last;
    if (key==='6h')  minX=last-6*H;
    if (key==='24h') minX=last-24*H;
    if (key==='3d')  minX=last-3*D;
    if (key==='7d')  minX=last-7*D;
    const first=series[0].x; if(minX<first) minX=first;
    setXRangeAll(minX,last);
  }
}

/* ===== Toggle tombol ===== */
let bbOn=false;
const toggleBtn=(el,on)=> el && el.classList.toggle('active', !!on);

document.addEventListener('click',(e)=>{
  const t=e.target;
  if (t.id==='btn-bb'){
    bbOn=!bbOn;
    // dataset index: 1=BBU, 2=BBL, 3=BBM (lihat data:datasets di chartHarga)
    [1,2,3].forEach(i=> chartHarga.getDatasetMeta(i).hidden = !bbOn);
    chartHarga.update('none'); toggleBtn(t,bbOn); return;
  }
  if (t.id==='btn-rsi'){
    const box=document.getElementById('chartRSIBox');
    const on=(box.style.display==='none'||box.style.display===''); box.style.display=on?'block':'none';
    toggleBtn(t,on); if(on){ chartRSI.resize(); chartRSI.update(); } return;
  }
  if (t.id==='btn-macd'){
    const box=document.getElementById('chartMACDBox');
    const on=(box.style.display==='none'||box.style.display===''); box.style.display=on?'block':'none';
    toggleBtn(t,on); if(on){ chartMACD.resize(); chartMACD.update(); } return;
  }
  if (t.dataset && t.dataset.range){ applyRange(t.dataset.range); return; }
  if (t.id==='btnResetRange'){ if(series.length){ setXRangeAll(series[0].x, series[series.length-1].x); } return; }
  if (t.id==='btn-signal-popup'){
    const modal=document.getElementById('modalOverlay');
    const tb=document.querySelector('#signalTable tbody'); tb.innerHTML='';
    if(!signals.length){ tb.innerHTML='<tr><td colspan="5">Tidak ada sinyal</td></tr>'; }
    else {
      signals.slice().reverse().forEach(s=>{
        tb.innerHTML+=`<tr>
          <td>${s.signal_type}</td>
          <td>${s.created_at}</td>
          <td>${(+s.price).toLocaleString('id-ID')}</td>
          <td>${s.reason}</td>
          <td>${s.confidence}</td>
        </tr>`;
      });
    }
    modal.style.display='flex'; return;
  }
  if (t.id==='closeModal'){ document.getElementById('modalOverlay').style.display='none'; return; }
});
document.getElementById('modalOverlay').addEventListener('click',e=>{ if(e.target===e.currentTarget) e.currentTarget.style.display='none'; });

/* ===== Default range 24h ===== */
if (series.length){
  const maxX=series[series.length-1].x;
  const minX=Math.max(series[0].x, maxX-24*3600*1000);
  setXRangeAll(minX,maxX);
}

/* ===== Marker BUY/SELL dari DB + fallback lokal (BUY) ===== */
let _buyPts=[], _sellPts=[];

(function placeMarkers(){
  if(!series.length) return;

  // 1) Pakai DB signals (toleransi 60 menit)
  if (signals.length){
    const tolSec = 60*60; // 60 menit
    const nearestIdx = (tsSec)=>{
      let best=-1, bestd=1e15;
      for(let i=0;i<epochs.length;i++){
        const d = Math.abs(epochs[i]-tsSec);
        if (d<bestd){bestd=d; best=i;}
      }
      return (bestd<=tolSec)?best:-1;
    };
    signals.forEach(s=>{
      const ts = (s.ts ?? Math.floor(new Date(s.created_at).getTime()/1000)); // epoch detik
      const idx = nearestIdx(ts);
      if (idx>=0){
        const pt = {
          x: series[idx].x,
          // y: +s.price, // <- aktifkan baris ini jika ingin posisi panah tepat di harga sinyal DB
          y: series[idx].y,
          reason: s.reason,
          conf: s.confidence
        };
        if ((s.signal_type||'').toUpperCase()==='SELL') _sellPts.push(pt); else _buyPts.push(pt);
      }
    });
  }

  // 2) Fallback: hitung lokal kalau DB tidak memberi panah BUY
  if (_buyPts.length===0 && series.length>1){
    const COOLDOWN_MIN = 30;
    const MIN_SCORE    = 2;
    let lastTs = 0;

    for (let i=1;i<series.length;i++){
      const reasons=[]; let score=0;

      // MACD cross up
      const mPrev=macdObj.macd[i-1], mNow=macdObj.macd[i];
      const sPrev=macdObj.signal[i-1], sNow=macdObj.signal[i];
      if (mPrev!=null && sPrev!=null && mNow!=null && sNow!=null && mPrev<sPrev && mNow>sNow){ reasons.push('MACD cross up'); score++; }

      // RSI rebound dari bawah 30
      const rPrev=rsiArr[i-1], rNow=rsiArr[i];
      if (rPrev!=null && rNow!=null && rPrev<30 && rNow>rPrev){ reasons.push('RSI rebound <30'); score++; }

      // Bounce BB lower
      const pPrev=pricesOnly[i-1], pNow=pricesOnly[i];
      const lbPrev=bbObj.lb[i-1], lbNow=bbObj.lb[i];
      if (lbPrev!=null && lbNow!=null && pPrev<=lbPrev && pNow>lbNow){ reasons.push('Bounce BB lower'); score++; }

      if (score>=MIN_SCORE){
        const ts = series[i].x;
        if (ts - lastTs >= COOLDOWN_MIN*60*1000){
          _buyPts.push({x: ts, y: series[i].y, reason: reasons.join(', '), conf: score});
          lastTs = ts;
        }
      }
    }
  }

  // tempel ke chart
  const iBuy = chartHarga.data.datasets.findIndex(d=>d.label==='BUY');
  const iSel = chartHarga.data.datasets.findIndex(d=>d.label==='SELL');
  if (iBuy>=0) chartHarga.data.datasets[iBuy].data = _buyPts;
  if (iSel>=0) chartHarga.data.datasets[iSel].data = _sellPts;
  chartHarga.update('none');
})();
</script>
</body>
</html>
