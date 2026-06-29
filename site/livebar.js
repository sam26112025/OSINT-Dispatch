/* IGRS Live Bar v2 — real-time clock, date & AQI. Free Open-Meteo APIs (no key).
   Default: New Delhi. Robust event-delegation build. */
(function(){
  "use strict";
  var AQI_BANDS=[
    {max:50,label:"Good",color:"#00ff88"},
    {max:100,label:"Moderate",color:"#ffb800"},
    {max:150,label:"Unhealthy (Sensitive)",color:"#ff7e00"},
    {max:200,label:"Unhealthy",color:"#ff3b3b"},
    {max:300,label:"Very Unhealthy",color:"#b56cff"},
    {max:99999,label:"Hazardous",color:"#ff2d6f"}
  ];
  var DEFAULT={name:"New Delhi",country:"India",lat:28.6139,lon:77.2090,tz:"Asia/Kolkata"};
  var QUICK=[
    {name:"New Delhi",lat:28.6139,lon:77.2090,tz:"Asia/Kolkata"},
    {name:"Mumbai",lat:19.0760,lon:72.8777,tz:"Asia/Kolkata"},
    {name:"Bengaluru",lat:12.9716,lon:77.5946,tz:"Asia/Kolkata"},
    {name:"Kolkata",lat:22.5726,lon:88.3639,tz:"Asia/Kolkata"},
    {name:"Chennai",lat:13.0827,lon:80.2707,tz:"Asia/Kolkata"},
    {name:"Hyderabad",lat:17.3850,lon:78.4867,tz:"Asia/Kolkata"},
    {name:"Pune",lat:18.5204,lon:73.8567,tz:"Asia/Kolkata"},
    {name:"Lucknow",lat:26.8467,lon:80.9462,tz:"Asia/Kolkata"}
  ];
  var state=null;
  try{var s=localStorage.getItem("igrs_livebar_loc");if(s)state=JSON.parse(s);}catch(e){}
  if(!state||typeof state.lat!=="number")state=DEFAULT;

  function band(a){for(var i=0;i<AQI_BANDS.length;i++){if(a<=AQI_BANDS[i].max)return AQI_BANDS[i];}return AQI_BANDS[5];}

  function qHTML(){
    var h="";for(var i=0;i<QUICK.length;i++){var q=QUICK[i];
      h+='<button type="button" class="ilb-pick" data-i="'+i+'">'+q.name+'</button>';}
    return h;
  }

  function buildHTML(){
    return '<div class="ilb-wrap">'
      +'<div class="ilb-block ilb-time"><div class="ilb-clock" id="ilb-clock">--:--:--</div><div class="ilb-date" id="ilb-date">Loading…</div></div>'
      +'<div class="ilb-sep"></div>'
      +'<div class="ilb-block ilb-aqi"><div class="ilb-aqi-top"><span class="ilb-aqi-val" id="ilb-aqi-val">…</span><span class="ilb-aqi-cat" id="ilb-aqi-cat">AQI</span></div><div class="ilb-loc" id="ilb-loc">'+state.name+'</div></div>'
      +'<button type="button" class="ilb-change" id="ilb-change">⚙ Location</button>'
      +'</div>'
      +'<div class="ilb-modal" id="ilb-modal" style="display:none;">'
        +'<div class="ilb-modal-card">'
          +'<div class="ilb-modal-head"><span>Choose Location</span><button type="button" class="ilb-close" id="ilb-close">✕</button></div>'
          +'<input type="text" class="ilb-search" id="ilb-search" placeholder="Search city, state or country…" autocomplete="off">'
          +'<div class="ilb-results" id="ilb-results"></div>'
          +'<div class="ilb-quick">'+qHTML()+'</div>'
        +'</div>'
      +'</div>';
  }

  function tick(){
    try{
      var now=new Date();
      var t=new Intl.DateTimeFormat('en-GB',{timeZone:state.tz,hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(now);
      var d=new Intl.DateTimeFormat('en-IN',{timeZone:state.tz,weekday:'short',day:'2-digit',month:'short',year:'numeric'}).format(now);
      var ce=document.getElementById('ilb-clock'),de=document.getElementById('ilb-date');
      if(ce)ce.textContent=t;
      if(de)de.textContent=d;
    }catch(e){}
  }

  function loadAQI(){
    var vEl=document.getElementById('ilb-aqi-val'),cEl=document.getElementById('ilb-aqi-cat'),lEl=document.getElementById('ilb-loc');
    if(lEl)lEl.textContent=state.name+(state.country?", "+state.country:"");
    if(vEl){vEl.textContent="…";vEl.style.color="#00d4ff";}
    if(cEl){cEl.textContent="loading";cEl.style.color="#7a8a99";}
    var url="https://air-quality-api.open-meteo.com/v1/air-quality?latitude="+encodeURIComponent(state.lat)+"&longitude="+encodeURIComponent(state.lon)+"&current=us_aqi,pm2_5,pm10";
    fetch(url,{mode:"cors"}).then(function(r){return r.json();}).then(function(j){
      var a=(j&&j.current&&j.current.us_aqi!=null)?Math.round(j.current.us_aqi):null;
      if(a==null||isNaN(a)){if(vEl){vEl.textContent="N/A";vEl.style.color="#7a8a99";}if(cEl){cEl.textContent="no data";cEl.style.color="#7a8a99";}return;}
      var b=band(a);
      if(vEl){vEl.textContent=a;vEl.style.color=b.color;}
      if(cEl){cEl.textContent=b.label;cEl.style.color=b.color;}
      if(cEl&&j.current.pm2_5!=null)cEl.title="PM2.5: "+Math.round(j.current.pm2_5)+" µg/m³";
    }).catch(function(err){
      if(vEl){vEl.textContent="—";vEl.style.color="#7a8a99";}
      if(cEl){cEl.textContent="offline";cEl.style.color="#7a8a99";}
    });
  }

  function setLoc(loc){
    state={name:loc.name,country:loc.country||"",lat:loc.lat,lon:loc.lon,tz:loc.tz||"Asia/Kolkata"};
    try{localStorage.setItem("igrs_livebar_loc",JSON.stringify(state));}catch(e){}
    var lEl=document.getElementById('ilb-loc');if(lEl)lEl.textContent=state.name+(state.country?", "+state.country:""); // immediate feedback
    tick();loadAQI();closeModal();
  }
  function openModal(){var m=document.getElementById('ilb-modal');if(m){m.style.display="flex";var s=document.getElementById('ilb-search');if(s){s.value="";setTimeout(function(){s.focus();},50);}var rs=document.getElementById('ilb-results');if(rs)rs.innerHTML="";}}
  function closeModal(){var m=document.getElementById('ilb-modal');if(m)m.style.display="none";}

  var searchTimer=null;
  function doSearch(q){
    var res=document.getElementById('ilb-results');if(!res)return;
    if(!q||q.length<2){res.innerHTML="";return;}
    res.innerHTML='<div class="ilb-hint">Searching…</div>';
    fetch("https://geocoding-api.open-meteo.com/v1/search?name="+encodeURIComponent(q)+"&count=8&language=en&format=json",{mode:"cors"})
    .then(function(r){return r.json();}).then(function(j){
      if(!j.results||!j.results.length){res.innerHTML='<div class="ilb-hint">No matches. Try a quick pick below.</div>';return;}
      res.innerHTML="";
      for(var i=0;i<j.results.length;i++){(function(p){
        var row=document.createElement('button');row.type="button";row.className="ilb-row";
        var sub=[p.admin1,p.country].filter(Boolean).join(", ");
        row.innerHTML='<strong>'+p.name+'</strong>'+(sub?'<span>'+sub+'</span>':'');
        row.onclick=function(){setLoc({name:p.name,country:p.country||"",lat:p.latitude,lon:p.longitude,tz:p.timezone||"Asia/Kolkata"});};
        res.appendChild(row);
      })(j.results[i]);}
    }).catch(function(){res.innerHTML='<div class="ilb-hint">Search unavailable right now. Use a quick pick below.</div>';});
  }

  function onClick(e){
    var t=e.target;
    // climb to find actionable element
    var el=t;
    while(el&&el!==document){
      if(el.id==='ilb-change'){openModal();return;}
      if(el.id==='ilb-close'){closeModal();return;}
      if(el.id==='ilb-modal'){closeModal();return;}
      if(el.className&&(""+el.className).indexOf('ilb-pick')>-1){var i=parseInt(el.getAttribute('data-i'),10);var q=QUICK[i];setLoc({name:q.name,country:"India",lat:q.lat,lon:q.lon,tz:q.tz});return;}
      el=el.parentNode;
    }
  }

  function init(){
    var host=document.getElementById('igrs-livebar');
    if(!host)return;
    host.innerHTML=buildHTML();
    // event delegation on document (robust against optimizers)
    host.addEventListener('click',onClick);
    var modal=document.getElementById('ilb-modal');
    if(modal)modal.addEventListener('click',onClick);
    var s=document.getElementById('ilb-search');
    if(s)s.addEventListener('input',function(){clearTimeout(searchTimer);var v=this.value;searchTimer=setTimeout(function(){doSearch(v);},350);});
    tick();setInterval(tick,1000);loadAQI();setInterval(loadAQI,600000);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
