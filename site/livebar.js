/* IGRS Live Bar — real-time clock, date & AQI widget
   Free APIs: Open-Meteo (no key). Default: New Delhi. User can search any city/country.
   Renders into <div id="igrs-livebar"></div> */
(function(){
  "use strict";
  var AQI_BANDS=[
    {max:50,label:"Good",color:"#00ff88"},
    {max:100,label:"Moderate",color:"#ffb800"},
    {max:150,label:"Unhealthy (Sensitive)",color:"#ff7e00"},
    {max:200,label:"Unhealthy",color:"#ff3b3b"},
    {max:300,label:"Very Unhealthy",color:"#b56cff"},
    {max:9999,label:"Hazardous",color:"#ff2d6f"}
  ];
  var DEFAULT={name:"New Delhi",country:"India",lat:28.6139,lon:77.2090,tz:"Asia/Kolkata"};
  var state=null;
  try{var s=localStorage.getItem("igrs_livebar_loc");if(s)state=JSON.parse(s);}catch(e){}
  if(!state)state=DEFAULT;

  function band(a){for(var i=0;i<AQI_BANDS.length;i++){if(a<=AQI_BANDS[i].max)return AQI_BANDS[i];}return AQI_BANDS[5];}
  function pad(n){return n<10?"0"+n:""+n;}

  function buildHTML(){
    return '<div class="ilb-wrap">'
      +'<div class="ilb-block ilb-time">'
        +'<div class="ilb-clock" id="ilb-clock">--:--:--</div>'
        +'<div class="ilb-date" id="ilb-date">Loading…</div>'
      +'</div>'
      +'<div class="ilb-sep"></div>'
      +'<div class="ilb-block ilb-aqi">'
        +'<div class="ilb-aqi-top"><span class="ilb-aqi-val" id="ilb-aqi-val">--</span>'
        +'<span class="ilb-aqi-cat" id="ilb-aqi-cat">AQI</span></div>'
        +'<div class="ilb-loc" id="ilb-loc">'+state.name+'</div>'
      +'</div>'
      +'<button class="ilb-change" id="ilb-change" aria-label="Change location" title="Change city / country">⚙ Location</button>'
      +'</div>'
      +'<div class="ilb-modal" id="ilb-modal" hidden>'
        +'<div class="ilb-modal-card">'
          +'<div class="ilb-modal-head"><span>Choose Location</span><button class="ilb-close" id="ilb-close" aria-label="Close">✕</button></div>'
          +'<input type="text" class="ilb-search" id="ilb-search" placeholder="Search city, state or country…" autocomplete="off">'
          +'<div class="ilb-results" id="ilb-results"></div>'
          +'<div class="ilb-quick">'
            +'<span data-lat="28.6139" data-lon="77.2090" data-name="New Delhi" data-tz="Asia/Kolkata">New Delhi</span>'
            +'<span data-lat="19.0760" data-lon="72.8777" data-name="Mumbai" data-tz="Asia/Kolkata">Mumbai</span>'
            +'<span data-lat="12.9716" data-lon="77.5946" data-name="Bengaluru" data-tz="Asia/Kolkata">Bengaluru</span>'
            +'<span data-lat="22.5726" data-lon="88.3639" data-name="Kolkata" data-tz="Asia/Kolkata">Kolkata</span>'
            +'<span data-lat="13.0827" data-lon="80.2707" data-name="Chennai" data-tz="Asia/Kolkata">Chennai</span>'
            +'<span data-lat="17.3850" data-lon="78.4867" data-name="Hyderabad" data-tz="Asia/Kolkata">Hyderabad</span>'
          +'</div>'
        +'</div>'
      +'</div>';
  }

  function tick(){
    try{
      var now=new Date();
      var t=new Intl.DateTimeFormat('en-GB',{timeZone:state.tz,hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(now);
      var d=new Intl.DateTimeFormat('en-IN',{timeZone:state.tz,weekday:'short',day:'2-digit',month:'short',year:'numeric'}).format(now);
      var ce=document.getElementById('ilb-clock');var de=document.getElementById('ilb-date');
      if(ce)ce.textContent=t;
      if(de)de.textContent=d+" · "+(state.tzShort||state.tz.split('/').pop().replace('_',' '));
    }catch(e){}
  }

  function loadAQI(){
    var vEl=document.getElementById('ilb-aqi-val');var cEl=document.getElementById('ilb-aqi-cat');var lEl=document.getElementById('ilb-loc');
    if(lEl)lEl.textContent=state.name+(state.country?", "+state.country:"");
    if(vEl){vEl.textContent="…";vEl.style.color="#00d4ff";}
    var url="https://air-quality-api.open-meteo.com/v1/air-quality?latitude="+state.lat+"&longitude="+state.lon+"&current=us_aqi,pm2_5,pm10&timezone=auto";
    fetch(url).then(function(r){return r.json();}).then(function(j){
      var a=j&&j.current?Math.round(j.current.us_aqi):null;
      if(a==null||isNaN(a)){if(vEl){vEl.textContent="N/A";vEl.style.color="#7a8a99";}if(cEl)cEl.textContent="AQI";return;}
      var b=band(a);
      if(vEl){vEl.textContent=a;vEl.style.color=b.color;}
      if(cEl){cEl.textContent=b.label;cEl.style.color=b.color;}
      var pm=j.current.pm2_5;
      if(cEl&&pm!=null)cEl.title="PM2.5: "+Math.round(pm)+" µg/m³";
    }).catch(function(){if(vEl){vEl.textContent="N/A";vEl.style.color="#7a8a99";}});
  }

  function setLoc(loc){
    state=loc;
    try{localStorage.setItem("igrs_livebar_loc",JSON.stringify(loc));}catch(e){}
    tick();loadAQI();closeModal();
  }
  function openModal(){var m=document.getElementById('ilb-modal');if(m){m.hidden=false;var s=document.getElementById('ilb-search');if(s){s.value="";s.focus();}document.getElementById('ilb-results').innerHTML="";}}
  function closeModal(){var m=document.getElementById('ilb-modal');if(m)m.hidden=true;}

  var searchTimer=null;
  function doSearch(q){
    var res=document.getElementById('ilb-results');
    if(!q||q.length<2){res.innerHTML="";return;}
    res.innerHTML='<div class="ilb-hint">Searching…</div>';
    fetch("https://geocoding-api.open-meteo.com/v1/search?name="+encodeURIComponent(q)+"&count=6&language=en&format=json")
    .then(function(r){return r.json();}).then(function(j){
      if(!j.results||!j.results.length){res.innerHTML='<div class="ilb-hint">No matches found.</div>';return;}
      res.innerHTML="";
      j.results.forEach(function(p){
        var row=document.createElement('button');row.className="ilb-row";
        var sub=[p.admin1,p.country].filter(Boolean).join(", ");
        row.innerHTML='<strong>'+p.name+'</strong>'+(sub?'<span>'+sub+'</span>':'');
        row.onclick=function(){setLoc({name:p.name,country:p.country||"",lat:p.latitude,lon:p.longitude,tz:p.timezone||"auto"});};
        res.appendChild(row);
      });
    }).catch(function(){res.innerHTML='<div class="ilb-hint">Search unavailable. Try a quick pick below.</div>';});
  }

  function init(){
    var host=document.getElementById('igrs-livebar');
    if(!host)return;
    host.innerHTML=buildHTML();
    document.getElementById('ilb-change').onclick=openModal;
    document.getElementById('ilb-close').onclick=closeModal;
    document.getElementById('ilb-modal').addEventListener('click',function(e){if(e.target.id==='ilb-modal')closeModal();});
    var s=document.getElementById('ilb-search');
    s.addEventListener('input',function(){clearTimeout(searchTimer);var v=this.value;searchTimer=setTimeout(function(){doSearch(v);},350);});
    Array.prototype.forEach.call(document.querySelectorAll('.ilb-quick span'),function(el){
      el.onclick=function(){setLoc({name:el.getAttribute('data-name'),country:"India",lat:parseFloat(el.getAttribute('data-lat')),lon:parseFloat(el.getAttribute('data-lon')),tz:el.getAttribute('data-tz')});};
    });
    tick();setInterval(tick,1000);loadAQI();setInterval(loadAQI,600000); // refresh AQI every 10 min
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
