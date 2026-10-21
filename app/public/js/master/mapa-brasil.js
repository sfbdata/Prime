/* BlueJus · Mapa do Brasil por estado (desenho docs/design/perfis-1.0/mapa-brasil.html), servido pelo Painel do Master.
   Portado do desenho. Única diferença de comportamento: o navegador não chama o IBGE. A malha das UFs é arquivo
   local e a malha/nomes dos municípios vêm de /master/ibge/municipios/{UF} (o servidor busca e guarda em cache). */
const CFG=JSON.parse(document.body.dataset.cfg||'{}');
const COD={11:'RO',12:'AC',13:'AM',14:'RR',15:'PA',16:'AP',17:'TO',21:'MA',22:'PI',23:'CE',24:'RN',25:'PB',26:'PE',27:'AL',28:'SE',29:'BA',31:'MG',32:'ES',33:'RJ',35:'SP',41:'PR',42:'SC',43:'RS',50:'MS',51:'MT',52:'GO',53:'DF'};
const UFCOD=Object.fromEntries(Object.entries(COD).map(([k,v])=>[v,k]));
const NOME={RO:'Rondônia',AC:'Acre',AM:'Amazonas',RR:'Roraima',PA:'Pará',AP:'Amapá',TO:'Tocantins',MA:'Maranhão',PI:'Piauí',CE:'Ceará',RN:'Rio Grande do Norte',PB:'Paraíba',PE:'Pernambuco',AL:'Alagoas',SE:'Sergipe',BA:'Bahia',MG:'Minas Gerais',ES:'Espírito Santo',RJ:'Rio de Janeiro',SP:'São Paulo',PR:'Paraná',SC:'Santa Catarina',RS:'Rio Grande do Sul',MS:'Mato Grosso do Sul',MT:'Mato Grosso',GO:'Goiás',DF:'Distrito Federal'};
const REG={N:'Norte',NE:'Nordeste',CO:'Centro-Oeste',SE:'Sudeste',S:'Sul'},RUF={RO:'N',AC:'N',AM:'N',RR:'N',PA:'N',AP:'N',TO:'N',MA:'NE',PI:'NE',CE:'NE',RN:'NE',PB:'NE',PE:'NE',AL:'NE',SE:'NE',BA:'NE',MG:'SE',ES:'SE',RJ:'SE',SP:'SE',PR:'S',SC:'S',RS:'S',MS:'CO',MT:'CO',GO:'CO',DF:'CO'};
const escH=window.BJAgregado.escH,dados={};let CID={},max=1;
// Contagens validadas vindas do servidor (/master/dados/mapa); a query string só escolhe a visão.
const AGREGADO=window.BJAgregado.carregar(CFG.dadosMapa).then(g=>{Object.assign(dados,g.A);CID=g.C;max=Math.max(1,...Object.values(dados))});
const cor=n=>!n?'#f7f9fb':d3.interpolateRgb('#9cc7e2','#0a4f7a')(Math.sqrt(n/max));
const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
const svg=d3.select('#m'),tip=document.getElementById('tip'),painel=document.getElementById('painel'),lista=document.getElementById('lista'),carreg=document.getElementById('carreg');
const W=innerWidth,H=innerHeight;svg.attr('viewBox','0 0 '+W+' '+H);
const zoomG=svg.append('g').attr('id','uZoom').append('g'),estG=zoomG.append('g'),munG=zoomG.append('g'),labG=zoomG.append('g').style('pointer-events','none');
let path,features,atual=null,cache={};
const mostrarTip=(e,t)=>{tip.textContent=t;tip.style.opacity=1;window.__tipE={x:e.clientX,y:e.clientY};cancelAnimationFrame(window.__tipR);window.__tipR=requestAnimationFrame(()=>{const p=window.__tipE,Wv=innerWidth,Hv=innerHeight,w=tip.offsetWidth,h=tip.offsetHeight;let x=p.x+18,y=p.y+22;if(x+w>Wv-8)x=Math.max(8,p.x-w-14);if(y+h>Hv-8)y=Math.max(8,p.y-h-14);tip.style.left=x+'px';tip.style.top=y+'px'})};
const rotulo=(g,x,y,t,k)=>{const w=(t.length*7+12)/k,h=18/k,gg=g.append('g').attr('transform','translate('+x+','+y+')');gg.append('rect').attr('x',-w/2).attr('y',-h/2).attr('width',w).attr('height',h).attr('rx',3/k).attr('fill','#12242f');gg.append('text').attr('text-anchor','middle').attr('y',4/k).attr('fill','#fff').attr('font-size',11.5/k).attr('font-weight','bold').text(t);return gg};
const rotEstados=()=>{labG.selectAll('*').remove();features.filter(f=>dados[uf(f)]).forEach(f=>{const [x,y]=path.centroid(f);rotulo(labG,x,y,String(dados[uf(f)]),1)})};
const uf=f=>COD[+String(f.properties.codarea).slice(0,2)];
function painelEstado(u,munNomes){
  const tot=dados[u]||0,cs=Object.entries(CID[u]||{}).sort((a,b)=>b[1]-a[1]),mx=Math.max(1,...cs.map(c=>c[1]));
  document.getElementById('pNome').innerHTML=escH(NOME[u])+'<span class="sub">Região '+escH(REG[RUF[u]])+' · '+(+tot)+' usuário(s)'+(munNomes?' · '+(+munNomes)+' municípios':'')+'</span>';
  lista.innerHTML=cs.length?cs.map(([n,v])=>'<div><i style="width:'+(v/mx*100)+'%"></i><span>'+escH(n)+'</span><span><b>'+(+v)+'</b></span></div>').join('')+(tot&&cs.reduce((a,c)=>a+c[1],0)<tot?'<div class="ins"><span>Cidade não informada</span><span>'+(tot-cs.reduce((a,c)=>a+c[1],0))+'</span></div>':''):'<div class="vazio">'+(tot?'Cidades ainda não informadas no cadastro.':'Nenhum usuário neste estado. Oportunidade de expansão.')+'</div>';
  painel.classList.add('on');
}
async function municipios(u){
  if(cache[u])return cache[u];
  const c=UFCOD[u];
  const r=await fetch(CFG.municipios.replace(/XX$/,u),{credentials:'same-origin',headers:{Accept:'application/json'}});if(!r.ok)throw 0;
  const {geo,nomes}=await r.json();if(!c||!geo||!geo.features)throw 0;
  geo.features.forEach(f=>{if(d3.geoArea(f)>2*Math.PI){const g=f.geometry;if(g.type==='Polygon')g.coordinates.forEach(r=>r.reverse());else g.coordinates.forEach(p=>p.forEach(r=>r.reverse()));}});
  const nm=Object.assign({},nomes||{});
  return cache[u]={geo,nm};
}
async function abrir(f){window.__zReset&&window.__zReset();window.__zReset&&window.__zReset();
  const u=uf(f);if(atual===u)return;atual=u;tip.style.opacity=0;
  const [[x0,y0],[x1,y1]]=path.bounds(f),k=Math.min(14,.82/Math.max((x1-x0)/W,(y1-y0)/H)),tx=W/2-k*(x0+x1)/2,ty=H/2-k*(y0+y1)/2;
  d3.select('#sombra feDropShadow').attr('dx',0).attr('dy',2/k).attr('stdDeviation',4/k);
  estG.selectAll('path').classed('sel',d=>d===f).attr('filter',d=>d===f?'url(#sombra)':null).style('opacity',d=>d===f?1:.12).attr('stroke-width',.8/k);
  estG.selectAll('path').filter(d=>d===f).raise();
  labG.selectAll('*').remove();
  munG.selectAll('*').remove();
  zoomG.transition().duration(850).ease(d3.easeCubicInOut).attr('transform','translate('+tx+','+ty+') scale('+k+')');
  painelEstado(u);
  carreg.style.opacity=1;
  try{
    const {geo,nm}=await municipios(u);if(atual!==u)return;
    const cs=CID[u]||{},cn={};Object.entries(cs).forEach(([n,v])=>cn[norm(n)]=v);const mxc=Math.max(1,...Object.values(cs));
    const nomeDe=g=>nm[String(g.properties.codarea)]||'Município';
    const val=g=>cn[norm(nomeDe(g))]||0;
    munG.selectAll('path').data(geo.features).join('path').attr('class','mun').attr('d',path).attr('stroke-width',.6/k).attr('fill',g=>val(g)?d3.interpolateRgb('#9cc7e2','#0a4f7a')(Math.sqrt(val(g)/mxc)):(window.__mkUF&&window.__mkUF(u)?'transparent':'#eef4f8')).style('opacity',0)
      .on('mousemove',(e,g)=>mostrarTip(e,nomeDe(g)+': '+(val(g)?val(g)+' usuário(s)':'sem usuários')))
      .on('mouseleave',()=>tip.style.opacity=0)
      .transition().delay(350).duration(500).style('opacity',1);
    labG.style('opacity',1).selectAll('*').remove();
    geo.features.filter(val).forEach(g=>{const [x,y]=path.centroid(g);const gg=rotulo(labG,x,y,String(val(g)),k);gg.style('opacity',0).transition().delay(650).duration(300).style('opacity',1)});
    painelEstado(u,geo.features.length);
  }catch(e){if(atual===u)lista.insertAdjacentHTML('beforeend','<div class="vazio">Não foi possível carregar os municípios do IBGE agora.</div>')}
  carreg.style.opacity=0;
}
function voltar(){window.__zReset&&window.__zReset();
  if(!atual)return;atual=null;tip.style.opacity=0;painel.classList.remove('on');carreg.style.opacity=0;
  munG.selectAll('path').transition().duration(250).style('opacity',0).remove();
  labG.selectAll('*').remove();
  estG.selectAll('path').attr('filter',null).style('opacity',1).attr('stroke-width',.8);
  zoomG.transition().duration(800).ease(d3.easeCubicInOut).attr('transform','translate(0,0) scale(1)').on('end',()=>{labG.style('opacity',1);rotEstados()});
}
document.getElementById('voltar').onclick=voltar;
addEventListener('keydown',e=>{if(e.key==='Escape')voltar()});
AGREGADO.then(()=>fetch(CFG.malhaUf))
.then(r=>{if(!r.ok)throw 0;return r.json()}).then(geo=>{
  if(!geo||!geo.features||!geo.features.length)throw 0;
  geo.features.forEach(f=>{if(d3.geoArea(f)>2*Math.PI){const g=f.geometry;if(g.type==='Polygon')g.coordinates.forEach(r=>r.reverse());else g.coordinates.forEach(p=>p.forEach(r=>r.reverse()));}});
  document.getElementById('msg').remove();
  features=geo.features;path=d3.geoPath(d3.geoMercator().fitExtent([[4,4],[W-4,H-4]],geo));
  svg.on('click',e=>{if(e.defaultPrevented)return;if(e.target===svg.node())voltar()});
  estG.selectAll('path').data(features).join('path').attr('class','uf').attr('d',path).attr('fill',f=>cor(dados[uf(f)]))
    .on('mousemove',(e,f)=>{if(atual)return tip.style.opacity=0;const u=uf(f),n=dados[u]||0;mostrarTip(e,NOME[u]+': '+(n?n+' usuário(s)':'sem atuação')+' · clique para detalhar · botão direito para marcar')})
    .on('mouseleave',()=>tip.style.opacity=0)
    .on('click',(e,f)=>{e.stopPropagation();if(atual&&atual!==uf(f))return voltar();abrir(f)});
  rotEstados();
}).catch(()=>{document.getElementById('msg').textContent='Não foi possível carregar o mapa do IBGE agora.'});

(()=>{const p=document.getElementById('painel'),t=p.querySelector('.top');t.style.cursor='grab';t.title='Arraste para mover';let sx,sy,ox,oy,on=false;
t.addEventListener('pointerdown',e=>{if(e.target.closest('button'))return;on=true;t.setPointerCapture(e.pointerId);t.style.cursor='grabbing';const r=p.getBoundingClientRect();sx=e.clientX;sy=e.clientY;ox=r.left;oy=r.top;p.style.transition='opacity .35s';e.preventDefault()});
t.addEventListener('pointermove',e=>{if(!on)return;const w=p.offsetWidth,h=p.offsetHeight;p.style.left=Math.max(0,Math.min(innerWidth-w,ox+e.clientX-sx))+'px';p.style.top=Math.max(0,Math.min(innerHeight-h,oy+e.clientY-sy))+'px'});
const fim=()=>{if(!on)return;on=false;t.style.cursor='grab';try{localStorage.setItem('bj-mapa-legenda',JSON.stringify({l:p.style.left,t:p.style.top}))}catch(e){}};
t.addEventListener('pointerup',fim);t.addEventListener('pointercancel',fim);
t.addEventListener('dblclick',e=>{if(e.target.closest('button'))return;p.style.left='10px';p.style.top='10px';localStorage.removeItem('bj-mapa-legenda')});
try{const s=JSON.parse(localStorage.getItem('bj-mapa-legenda')||'null');if(s){p.style.left=s.l;p.style.top=s.t}}catch(e){}})();

(()=>{const svg=d3.select('#m'),uG=d3.select('#uZoom');const z=d3.zoom().scaleExtent([1,12]).filter(e=>(e.type==='wheel'||e.type==='touchstart'||e.type==='mousedown'&&e.button===0||e.type==='dblclick')).on('zoom',e=>{uG.attr('transform',e.transform);document.getElementById('zRes').style.opacity=e.transform.k>1.01?1:.4});
svg.call(z).on('dblclick.zoom',null);
window.__zReset=()=>svg.transition().duration(500).call(z.transform,d3.zoomIdentity);
const b=document.createElement('div');b.id='zCtl';b.innerHTML='<button data-z="1.6" title="Aproximar">+</button><button data-z="0.625" title="Afastar">&minus;</button><button id="zRes" title="Ajustar ao mapa" style="opacity:.4">&#10530;</button>';document.body.appendChild(b);
b.querySelectorAll('button[data-z]').forEach(x=>x.onclick=()=>svg.transition().duration(350).call(z.scaleBy,+x.dataset.z));document.getElementById('zRes').onclick=window.__zReset;})();

(()=>{const uG=document.getElementById('uZoom');let uk=1;
function aplicar(){uG.querySelectorAll('g').forEach(g=>{const r=g.querySelector(':scope>rect');if(!r||r.getAttribute('fill')!=='#12242f')return;if(g.__t==null)g.__t=(g.getAttribute('transform')||'').replace(/\s*scale\([^)]*\)/,'');g.setAttribute('transform',g.__t+(uk!==1?' scale('+(1/uk)+')':''))});
uG.querySelectorAll('path').forEach(p=>{if(p.__sw==null){const v=parseFloat(p.getAttribute('stroke-width')||getComputedStyle(p).strokeWidth);p.__sw=isNaN(v)?.5:v}p.setAttribute('stroke-width',p.__sw*(uk>1?1.8:1)/uk)})}
let nG=0;setInterval(()=>{const t=uG.getAttribute('transform')||'',m=t.match(/scale\(([\d.]+)/),k=m?+m[1]:1,n=uG.querySelectorAll('path').length;if(n!==nG){nG=n;if(k===1)uG.querySelectorAll('path').forEach(p=>p.__sw=null);uk=k;aplicar();return}if(Math.abs(k-uk)>1e-4){uk=k;aplicar()}},150);
const p=document.getElementById('painel');const prender=()=>{const w=p.offsetWidth,h=p.offsetHeight;if(!p.style.left)return;p.style.left=Math.max(0,Math.min(innerWidth-w,parseFloat(p.style.left)))+'px';p.style.top=Math.max(0,Math.min(innerHeight-h,parseFloat(p.style.top)))+'px'};
addEventListener('resize',prender);
p.querySelector('.top').addEventListener('pointermove',()=>requestAnimationFrame(prender));prender();})();

/* marcação de estados e municípios por cor (botão direito), guardada no navegador */
(()=>{const KEY='bj-mapa-marcas',CORES=['#fde68a','#fca5a5','#fdba74','#86efac','#5eead4','#93c5fd','#c4b5fd','#f9a8d4','#d6d3d1','#fcd34d','#4ade80','#60a5fa'];
window.__mkUF=u=>{try{return (JSON.parse(localStorage.getItem('bj-mapa-marcas'))||{uf:{}}).uf[u]}catch(e){return null}};let M;try{M=JSON.parse(localStorage.getItem(KEY))||{}}catch(e){M={}}M.uf=M.uf||{};M.mun=M.mun||{};
const salvar=()=>localStorage.setItem(KEY,JSON.stringify(M)),mk=document.getElementById('mk');
const esc=c=>c?d3.color(c).darker(1.3).formatHex():null,markG=zoomG.insert('g',()=>munG.node()).attr('id','mkMun').style('pointer-events','none');
let ver=0;
const desenharNacional=async()=>{const v=++ver;markG.selectAll('*').remove();if(atual)return;const ufs=[...new Set(Object.keys(M.mun).map(k=>COD[+k.slice(0,2)]).filter(Boolean))];
 for(const u of ufs){let r;try{r=await municipios(u)}catch(e){continue}if(v!==ver||atual)return;
  markG.append('g').selectAll('path').data(r.geo.features.filter(g=>M.mun[String(g.properties.codarea)])).join('path').attr('d',path).attr('fill',g=>M.mun[String(g.properties.codarea)]).attr('stroke',g=>esc(M.mun[String(g.properties.codarea)])).attr('stroke-width',.9).attr('vector-effect','non-scaling-stroke').style('opacity',0).transition().duration(300).style('opacity',1);}
};
const aplicar=()=>{estG.selectAll('path.uf').style('fill',d=>M.uf[uf(d)]||null).style('stroke',d=>esc(M.uf[uf(d)])).style('stroke-width',d=>M.uf[uf(d)]?'1.6px':null).attr('vector-effect',d=>M.uf[uf(d)]?'non-scaling-stroke':null);
 munG.selectAll('path.mun').style('fill',d=>M.mun[String(d.properties.codarea)]||null).style('stroke',d=>{const c=M.mun[String(d.properties.codarea)];if(c)return esc(c);const p=atual&&M.uf[atual];return p?esc(p):null});
 markG.style('display',atual?'none':null);};
const aplicarTudo=()=>{aplicar();desenharNacional()};
new MutationObserver(aplicar).observe(munG.node(),{childList:true});new MutationObserver(aplicarTudo).observe(estG.node(),{childList:true});new MutationObserver(()=>{aplicar();if(!atual)desenharNacional()}).observe(document.getElementById('painel'),{attributes:true,attributeFilter:['class']});
const fechar=()=>mk.style.display='none';
document.addEventListener('contextmenu',e=>{const p=e.target.closest&&e.target.closest('path.uf,path.mun');if(!p)return;e.preventDefault();e.stopPropagation();tip.style.opacity=0;
 const d=d3.select(p).datum(),ehMun=p.classList.contains('mun'),chave=ehMun?String(d.properties.codarea):uf(d),grupo=ehMun?'mun':'uf';
 const nome=ehMun?((cache[atual]&&cache[atual].nm[chave])||'Município'):NOME[chave],atualCor=M[grupo][chave];
 mk.innerHTML='<div class="t">'+escH(nome)+'</div><div class="s">'+(ehMun?'MARCAR MUNICÍPIO':'MARCAR ESTADO')+'</div><div class="g">'+CORES.map(c=>'<button data-c="'+c+'" title="'+c+'" class="'+(c===atualCor?'on':'')+'" style="background:'+c+'"></button>').join('')+'</div>'+(atualCor?'<button class="x" data-c="">Remover marcação</button>':'');
 mk.querySelectorAll('button').forEach(b=>b.onclick=ev=>{ev.stopPropagation();const c=b.dataset.c;if(c)M[grupo][chave]=c;else delete M[grupo][chave];salvar();aplicarTudo();fechar()});
 mk.style.display='block';const w=mk.offsetWidth,hh=mk.offsetHeight;mk.style.left=Math.max(6,Math.min(innerWidth-w-6,e.clientX))+'px';mk.style.top=Math.max(6,Math.min(innerHeight-hh-6,e.clientY))+'px'},true);
document.addEventListener('pointerdown',e=>{if(!mk.contains(e.target))fechar()});addEventListener('keydown',e=>{if(e.key==='Escape')fechar()});addEventListener('wheel',fechar,{passive:true});
addEventListener('storage',e=>{if(e.key===KEY){try{M=JSON.parse(e.newValue)||{}}catch(x){M={}}M.uf=M.uf||{};M.mun=M.mun||{};aplicarTudo()}});
setTimeout(aplicarTudo,800);})();

/* Seccionais da OAB (spec docs/specs/master-seccionais-oab.md; desenho 05, Perfis 1.2, VS 21.10.26, L116-127).
   Um marcador por seccional no centroide da UF (d3.geoCentroid; DONO: a posição exata da sede), escondido quando o
   Master oculta a seccional no painel (mesma chave de localStorage do painel.js; o evento storage sincroniza), e
   "Registrar divulgação" no menu do botão direito sobre a UF, acima das cores, que continuam como estão. Só na
   visão Pessoas (quem=pes), como o bloco do painel. Depois de gravar, avisa o painel (window.parent, como o __bjFontesAuto do mapa-dados.js) para recarregar. */
(()=>{// Mesmo recorte do bloco do painel (Brasil + Pessoas): com quem=esc não há marcador nem divulgação. Leitura igual à do mapa-agregado.js.
if((new URLSearchParams(location.search).get('quem')==='esc'?'esc':'pes')!=='pes')return;
const KEY='bj-mapa-seccionais-ocultas',COR={material:'#0a6fa0',reuniao:'#b7791f',parceria:'#2f855a'},TIPOS=[['material','Material enviado'],['reuniao','Reunião'],['parceria','Parceria']];
const mk=document.getElementById('mk'),uZ=document.getElementById('uZoom'),secG=zoomG.append('g').attr('id','secMk');let SEC={};
const ocultas=()=>{try{const v=JSON.parse(localStorage.getItem(KEY)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}};
const dataBr=d=>{const p=String(d||'').split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:''};
const hoje=()=>{const d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')};
const titulo=u=>{const s=SEC[u];return s.nome+(s.ultima?' · última divulgação: '+s.ultima.rotulo+' em '+dataBr(s.ultima.data):' · sem divulgação registrada')};
const raio=()=>{const m=(uZ.getAttribute('transform')||'').match(/scale\(([\d.]+)/);return 5/(m?+m[1]:1)};
const menuDaUf=(e,f)=>{const alvo=estG.selectAll('path.uf').filter(d=>d===f).node();if(alvo)alvo.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,cancelable:true,clientX:e.clientX,clientY:e.clientY}))};
function desenhar(){secG.selectAll('*').remove();secG.style('display',atual?'none':null);if(!features||!path)return;const oc=ocultas(),proj=path.projection();
 features.filter(f=>SEC[uf(f)]&&!oc.includes(uf(f))).forEach(f=>{const u=uf(f),p=proj(d3.geoCentroid(f)),ult=SEC[u].ultima;if(!p)return;
  secG.append('circle').datum({seccional:u}).attr('class','sec-mk').attr('cx',p[0]).attr('cy',p[1]).attr('r',raio()).style('fill',ult&&COR[ult.tipo]?COR[ult.tipo]:null)
   .on('mousemove',e=>mostrarTip(e,titulo(u))).on('mouseleave',()=>tip.style.opacity=0)
   .on('click',e=>{e.stopPropagation();abrir(f)})
   .on('contextmenu',e=>{e.preventDefault();e.stopPropagation();menuDaUf(e,f)});});}
const carregar=()=>fetch(CFG.seccionais,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.ok?r.json():Promise.reject(r.status))
 .then(j=>{SEC={};(j.seccionais||[]).forEach(s=>SEC[s.uf]=s);desenhar()}).catch(()=>{});
const avisarPainel=()=>{try{if(window.parent&&window.parent!==window&&typeof window.parent.__bjSeccionaisRecarregar==='function')window.parent.__bjSeccionaisRecarregar()}catch(e){}};
function registrar(u,tipo,dv){const msg=dv.querySelector('[data-dv-msg]'),travar=on=>dv.querySelectorAll('button').forEach(b=>b.disabled=on);travar(true);msg.className='m';msg.textContent='Registrando…';
 fetch(CFG.divulgar.replace('/XX/','/'+u+'/'),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':CFG.csrfSeccionais},
  body:JSON.stringify({tipo,nota:dv.querySelector('[data-dv-nota]').value,data:dv.querySelector('[data-dv-data]').value})})
 .then(r=>r.json().catch(()=>null).then(j=>({r,j}))).then(({r,j})=>{if(!r.redirected&&r.ok&&j&&j.mensagem){msg.className='m ok';msg.textContent=j.mensagem;avisarPainel();carregar();setTimeout(()=>{if(mk.contains(dv))mk.style.display='none'},1200);return}throw (j&&!r.ok&&j.erro)||null})
 .catch(erro=>{msg.className='m erro';msg.textContent=typeof erro==='string'?erro:'Não foi possível registrar agora. Recarregue a página.';travar(false)});}
// Depois do menu das cores (os dois ouvem na captura; este foi registrado depois): entra acima de "MARCAR ESTADO".
document.addEventListener('contextmenu',e=>{const p=e.target.closest&&e.target.closest('path.uf');if(!p||mk.style.display!=='block')return;const u=uf(d3.select(p).datum());if(!SEC[u])return;
 const dv=document.createElement('div');dv.className='dv';
 dv.innerHTML='<div class="s">Registrar divulgação</div><div class="b">'+TIPOS.map(([k,r])=>'<button type="button" data-dv="'+k+'"><i style="background:'+COR[k]+'"></i>'+escH(r)+'</button>').join('')+'</div>'
  +'<input type="text" maxlength="500" placeholder="Nota (opcional)" aria-label="Nota (opcional)" data-dv-nota><input type="date" aria-label="Data da divulgação" value="'+hoje()+'" max="'+hoje()+'" data-dv-data><div class="m" data-dv-msg>'+escH(titulo(u))+'</div>';
 mk.querySelector('.t').after(dv);
 dv.querySelectorAll('[data-dv]').forEach(b=>b.onclick=ev=>{ev.stopPropagation();registrar(u,b.dataset.dv,dv)});
 mk.style.top=Math.max(6,Math.min(innerHeight-mk.offsetHeight-6,e.clientY))+'px'},true);
new MutationObserver(desenhar).observe(estG.node(),{childList:true});
new MutationObserver(desenhar).observe(document.getElementById('painel'),{attributes:true,attributeFilter:['class']});
new MutationObserver(()=>secG.selectAll('circle').attr('r',raio())).observe(uZ,{attributes:true,attributeFilter:['transform']});
addEventListener('storage',e=>{if(e.key===KEY)desenhar()});
carregar();})();
