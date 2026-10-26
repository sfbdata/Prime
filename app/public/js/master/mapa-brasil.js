/* BlueJus · Mapa do Brasil por estado (desenho docs/design/perfis-1.0/mapa-brasil.html), servido pelo Painel do Master.
   Portado do desenho. Única diferença de comportamento: o navegador não chama o IBGE. A malha das UFs é arquivo
   local e a malha/nomes dos municípios vêm de /master/ibge/municipios/{UF} (o servidor busca e guarda em cache).
   Idioma (spec idioma-do-sistema): os textos da tela vêm do catálogo da página (master.mapa_brasil.*, por BJ_T.t); os
   nomes dos estados ficam em português (dado, decisão do dono).
   Modo OAB (quem=oab; desenho 05, Perfis 1.2, VS 21.10.26, L875 e L902): o número da UF é o de inscrições por
   seccional (GET das seccionais), a cor da UF é a da marca da divulgação e o botão direito registra a divulgação. */
const CFG=JSON.parse(document.body.dataset.cfg||'{}');
const T=(chave,params)=>window.BJ_T.t(chave,params);
const MODO_OAB=new URLSearchParams(location.search).get('quem')==='oab';
const QS=new URLSearchParams(location.search),MODO_IND=QS.get('quem')==='ind'?(['ok','pago'].includes(QS.get('sub'))?QS.get('sub'):'env'):null;
const unidade=n=>MODO_OAB?T('master.mapa_brasil.inscricoes',{n}):MODO_IND?T('master.mapa_brasil.ind_'+MODO_IND,{n}):T('master.mapa_brasil.pessoas',{n});
const COD={11:'RO',12:'AC',13:'AM',14:'RR',15:'PA',16:'AP',17:'TO',21:'MA',22:'PI',23:'CE',24:'RN',25:'PB',26:'PE',27:'AL',28:'SE',29:'BA',31:'MG',32:'ES',33:'RJ',35:'SP',41:'PR',42:'SC',43:'RS',50:'MS',51:'MT',52:'GO',53:'DF'};
const UFCOD=Object.fromEntries(Object.entries(COD).map(([k,v])=>[v,k]));
const NOME={RO:'Rondônia',AC:'Acre',AM:'Amazonas',RR:'Roraima',PA:'Pará',AP:'Amapá',TO:'Tocantins',MA:'Maranhão',PI:'Piauí',CE:'Ceará',RN:'Rio Grande do Norte',PB:'Paraíba',PE:'Pernambuco',AL:'Alagoas',SE:'Sergipe',BA:'Bahia',MG:'Minas Gerais',ES:'Espírito Santo',RJ:'Rio de Janeiro',SP:'São Paulo',PR:'Paraná',SC:'Santa Catarina',RS:'Rio Grande do Sul',MS:'Mato Grosso do Sul',MT:'Mato Grosso',GO:'Goiás',DF:'Distrito Federal'};
const REG={N:T('master.mapa_brasil.regiao.norte'),NE:T('master.mapa_brasil.regiao.nordeste'),CO:T('master.mapa_brasil.regiao.centro_oeste'),SE:T('master.mapa_brasil.regiao.sudeste'),S:T('master.mapa_brasil.regiao.sul')},RUF={RO:'N',AC:'N',AM:'N',RR:'N',PA:'N',AP:'N',TO:'N',MA:'NE',PI:'NE',CE:'NE',RN:'NE',PB:'NE',PE:'NE',AL:'NE',SE:'NE',BA:'NE',MG:'SE',ES:'SE',RJ:'SE',SP:'SE',PR:'S',SC:'S',RS:'S',MS:'CO',MT:'CO',GO:'CO',DF:'CO'};
const escH=window.BJAgregado.escH,dados={};let CID={},max=1;
// Contagens validadas vindas do servidor (/master/dados/mapa); a query string só escolhe a visão. No modo OAB o
// agregado lê as pessoas (quem=oab cai em pes): as cidades ficam as delas e o número da UF passa a ser o de inscrições.
const inscricoes=()=>fetch(CFG.seccionais,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.ok?r.json():Promise.reject(r.status))
 .then(j=>{const A={};(j.seccionais||[]).forEach(x=>{A[x.uf]=x.inscricoes});return window.BJAgregado.validar({A}).A}).catch(()=>({}));
const AGREGADO=window.BJAgregado.carregar(CFG.dadosMapa).then(g=>MODO_OAB?inscricoes().then(A=>({A,C:g.C})):g).then(g=>{Object.assign(dados,g.A);CID=g.C;max=Math.max(1,...Object.values(dados))});
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
  document.getElementById('pNome').innerHTML=escH(NOME[u])+'<span class="sub">'+escH(T('master.mapa_brasil.regiao_sub',{regiao:REG[RUF[u]]}))+' · '+escH(unidade(+tot))+(munNomes?' · '+escH(T('master.mapa_brasil.municipios',{n:+munNomes})):'')+'</span>';
  lista.innerHTML=cs.length?cs.map(([n,v])=>'<div><i style="width:'+(v/mx*100)+'%"></i><span>'+escH(n)+'</span><span><b>'+(+v)+'</b></span></div>').join('')+(!MODO_OAB&&tot&&cs.reduce((a,c)=>a+c[1],0)<tot?'<div class="ins"><span>'+escH(T('master.mapa_brasil.cidade_nao_informada'))+'</span><span>'+(tot-cs.reduce((a,c)=>a+c[1],0))+'</span></div>':''):'<div class="vazio">'+escH(tot?T('master.mapa_brasil.cidades_vazias'):T('master.mapa_brasil.sem_usuarios_estado'))+'</div>';
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
    const nomeDe=g=>nm[String(g.properties.codarea)]||T('master.mapa_brasil.municipio');
    const val=g=>cn[norm(nomeDe(g))]||0;
    munG.selectAll('path').data(geo.features).join('path').attr('class','mun').attr('d',path).attr('stroke-width',.6/k).attr('fill',g=>val(g)?d3.interpolateRgb('#9cc7e2','#0a4f7a')(Math.sqrt(val(g)/mxc)):(window.__mkUF&&window.__mkUF(u)?'transparent':'#eef4f8')).style('opacity',0)
      .on('mousemove',(e,g)=>mostrarTip(e,nomeDe(g)+': '+(val(g)?T('master.mapa_brasil.pessoas',{n:val(g)}):T('master.mapa_brasil.sem_usuarios'))))
      .on('mouseleave',()=>tip.style.opacity=0)
      .transition().delay(350).duration(500).style('opacity',1);
    labG.style('opacity',1).selectAll('*').remove();
    geo.features.filter(val).forEach(g=>{const [x,y]=path.centroid(g);const gg=rotulo(labG,x,y,String(val(g)),k);gg.style('opacity',0).transition().delay(650).duration(300).style('opacity',1)});
    painelEstado(u,geo.features.length);
  }catch(e){if(atual===u)lista.insertAdjacentHTML('beforeend','<div class="vazio">'+escH(T('master.mapa_brasil.erro_municipios'))+'</div>')}
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
    .on('mousemove',(e,f)=>{if(atual)return tip.style.opacity=0;const u=uf(f),n=dados[u]||0;mostrarTip(e,NOME[u]+': '+(n?unidade(n):T('master.mapa_brasil.sem_atuacao'))+T(MODO_OAB?'master.mapa_brasil.dica_estado_oab':'master.mapa_brasil.dica_estado'))})
    .on('mouseleave',()=>tip.style.opacity=0)
    .on('click',(e,f)=>{e.stopPropagation();if(atual&&atual!==uf(f))return voltar();abrir(f)});
  rotEstados();
}).catch(()=>{document.getElementById('msg').textContent=T('master.mapa_brasil.erro_mapa')});

(()=>{const p=document.getElementById('painel'),t=p.querySelector('.top');t.style.cursor='grab';t.title=T('master.mapa_brasil.arraste');let sx,sy,ox,oy,on=false;
t.addEventListener('pointerdown',e=>{if(e.target.closest('button'))return;on=true;t.setPointerCapture(e.pointerId);t.style.cursor='grabbing';const r=p.getBoundingClientRect();sx=e.clientX;sy=e.clientY;ox=r.left;oy=r.top;p.style.transition='opacity .35s';e.preventDefault()});
t.addEventListener('pointermove',e=>{if(!on)return;const w=p.offsetWidth,h=p.offsetHeight;p.style.left=Math.max(0,Math.min(innerWidth-w,ox+e.clientX-sx))+'px';p.style.top=Math.max(0,Math.min(innerHeight-h,oy+e.clientY-sy))+'px'});
const fim=()=>{if(!on)return;on=false;t.style.cursor='grab';try{localStorage.setItem('bj-mapa-legenda',JSON.stringify({l:p.style.left,t:p.style.top}))}catch(e){}};
t.addEventListener('pointerup',fim);t.addEventListener('pointercancel',fim);
t.addEventListener('dblclick',e=>{if(e.target.closest('button'))return;p.style.left='10px';p.style.top='10px';localStorage.removeItem('bj-mapa-legenda')});
try{const s=JSON.parse(localStorage.getItem('bj-mapa-legenda')||'null');if(s){p.style.left=s.l;p.style.top=s.t}}catch(e){}})();

(()=>{const svg=d3.select('#m'),uG=d3.select('#uZoom');const z=d3.zoom().scaleExtent([1,12]).filter(e=>(e.type==='wheel'||e.type==='touchstart'||e.type==='mousedown'&&e.button===0||e.type==='dblclick')).on('zoom',e=>{uG.attr('transform',e.transform);document.getElementById('zRes').style.opacity=e.transform.k>1.01?1:.4});
svg.call(z).on('dblclick.zoom',null);
window.__zReset=()=>svg.transition().duration(500).call(z.transform,d3.zoomIdentity);
const b=document.createElement('div');b.id='zCtl';b.innerHTML='<button data-z="1.6" title="'+escH(T('master.mapa_brasil.aproximar'))+'">+</button><button data-z="0.625" title="'+escH(T('master.mapa_brasil.afastar'))+'">&minus;</button><button id="zRes" title="'+escH(T('master.mapa_brasil.ajustar'))+'" style="opacity:.4">&#10530;</button>';document.body.appendChild(b);
b.querySelectorAll('button[data-z]').forEach(x=>x.onclick=()=>svg.transition().duration(350).call(z.scaleBy,+x.dataset.z));document.getElementById('zRes').onclick=window.__zReset;})();

(()=>{const uG=document.getElementById('uZoom');let uk=1;
function aplicar(){uG.querySelectorAll('g').forEach(g=>{const r=g.querySelector(':scope>rect');if(!r||r.getAttribute('fill')!=='#12242f')return;if(g.__t==null)g.__t=(g.getAttribute('transform')||'').replace(/\s*scale\([^)]*\)/,'');g.setAttribute('transform',g.__t+(uk!==1?' scale('+(1/uk)+')':''))});
uG.querySelectorAll('path').forEach(p=>{if(p.__sw==null){const v=parseFloat(p.getAttribute('stroke-width')||getComputedStyle(p).strokeWidth);p.__sw=isNaN(v)?.5:v}p.setAttribute('stroke-width',p.__sw*(uk>1?1.8:1)/uk)})}
let nG=0;setInterval(()=>{const t=uG.getAttribute('transform')||'',m=t.match(/scale\(([\d.]+)/),k=m?+m[1]:1,n=uG.querySelectorAll('path').length;if(n!==nG){nG=n;if(k===1)uG.querySelectorAll('path').forEach(p=>p.__sw=null);uk=k;aplicar();return}if(Math.abs(k-uk)>1e-4){uk=k;aplicar()}},150);
const p=document.getElementById('painel');const prender=()=>{const w=p.offsetWidth,h=p.offsetHeight;if(!p.style.left)return;p.style.left=Math.max(0,Math.min(innerWidth-w,parseFloat(p.style.left)))+'px';p.style.top=Math.max(0,Math.min(innerHeight-h,parseFloat(p.style.top)))+'px'};
addEventListener('resize',prender);
p.querySelector('.top').addEventListener('pointermove',()=>requestAnimationFrame(prender));prender();})();

/* marcação de estados e municípios por cor (botão direito), guardada no navegador. No modo OAB a cor do estado é a da
   marca da divulgação, vinda do servidor (window.__secCorUf, do bloco das seccionais abaixo), e o botão direito no
   estado abre o menu da divulgação; os municípios continuam com a marcação do navegador. */
const CORES=['#fde68a','#fca5a5','#fdba74','#86efac','#5eead4','#93c5fd','#c4b5fd','#f9a8d4','#d6d3d1','#fcd34d','#4ade80','#60a5fa'];
(()=>{const KEY='bj-mapa-marcas';
const corLocal=u=>{try{return (JSON.parse(localStorage.getItem('bj-mapa-marcas'))||{uf:{}}).uf[u]}catch(e){return null}};
window.__mkUF=u=>MODO_OAB?(window.__secCorUf?window.__secCorUf(u):null):corLocal(u);let M;try{M=JSON.parse(localStorage.getItem(KEY))||{}}catch(e){M={}}M.uf=M.uf||{};M.mun=M.mun||{};
const salvar=()=>localStorage.setItem(KEY,JSON.stringify(M)),mk=document.getElementById('mk');
const esc=c=>c?d3.color(c).darker(1.3).formatHex():null,markG=zoomG.insert('g',()=>munG.node()).attr('id','mkMun').style('pointer-events','none');
let ver=0;
const desenharNacional=async()=>{const v=++ver;markG.selectAll('*').remove();if(atual)return;const ufs=[...new Set(Object.keys(M.mun).map(k=>COD[+k.slice(0,2)]).filter(Boolean))];
 for(const u of ufs){let r;try{r=await municipios(u)}catch(e){continue}if(v!==ver||atual)return;
  markG.append('g').selectAll('path').data(r.geo.features.filter(g=>M.mun[String(g.properties.codarea)])).join('path').attr('d',path).attr('fill',g=>M.mun[String(g.properties.codarea)]).attr('stroke',g=>esc(M.mun[String(g.properties.codarea)])).attr('stroke-width',.9).attr('vector-effect','non-scaling-stroke').style('opacity',0).transition().duration(300).style('opacity',1);}
};
const corUf=u=>MODO_OAB?window.__mkUF(u):M.uf[u];
const aplicar=()=>{estG.selectAll('path.uf').style('fill',d=>corUf(uf(d))||null).style('stroke',d=>esc(corUf(uf(d)))).style('stroke-width',d=>corUf(uf(d))?'1.6px':null).attr('vector-effect',d=>corUf(uf(d))?'non-scaling-stroke':null);
 munG.selectAll('path.mun').style('fill',d=>M.mun[String(d.properties.codarea)]||null).style('stroke',d=>{const c=M.mun[String(d.properties.codarea)];if(c)return esc(c);const p=atual&&corUf(atual);return p?esc(p):null});
 markG.style('display',atual?'none':null);};
const aplicarTudo=()=>{aplicar();desenharNacional()};window.__mkAplicar=aplicarTudo;
new MutationObserver(aplicar).observe(munG.node(),{childList:true});new MutationObserver(aplicarTudo).observe(estG.node(),{childList:true});new MutationObserver(()=>{aplicar();if(!atual)desenharNacional()}).observe(document.getElementById('painel'),{attributes:true,attributeFilter:['class']});
const fechar=()=>mk.style.display='none';
document.addEventListener('contextmenu',e=>{const p=e.target.closest&&e.target.closest('path.uf,path.mun');if(!p||MODO_OAB&&p.classList.contains('uf'))return;e.preventDefault();e.stopPropagation();tip.style.opacity=0;
 const d=d3.select(p).datum(),ehMun=p.classList.contains('mun'),chave=ehMun?String(d.properties.codarea):uf(d),grupo=ehMun?'mun':'uf';
 const nome=ehMun?((cache[atual]&&cache[atual].nm[chave])||T('master.mapa_brasil.municipio')):NOME[chave],atualCor=M[grupo][chave];
 mk.innerHTML='<div class="t">'+escH(nome)+'</div><div class="s">'+escH(T(ehMun?'master.mapa_brasil.marcar_municipio':'master.mapa_brasil.marcar_estado'))+'</div><div class="g">'+CORES.map(c=>'<button data-c="'+c+'" title="'+c+'" class="'+(c===atualCor?'on':'')+'" style="background:'+c+'"></button>').join('')+'</div>'+(atualCor?'<button class="x" data-c="">'+escH(T('master.mapa_brasil.remover_marcacao'))+'</button>':'');
 mk.querySelectorAll('button').forEach(b=>b.onclick=ev=>{ev.stopPropagation();const c=b.dataset.c;if(c)M[grupo][chave]=c;else delete M[grupo][chave];salvar();aplicarTudo();fechar()});
 mk.style.display='block';const w=mk.offsetWidth,hh=mk.offsetHeight;mk.style.left=Math.max(6,Math.min(innerWidth-w-6,e.clientX))+'px';mk.style.top=Math.max(6,Math.min(innerHeight-hh-6,e.clientY))+'px'},true);
document.addEventListener('pointerdown',e=>{if(!mk.contains(e.target))fechar()});addEventListener('keydown',e=>{if(e.key==='Escape')fechar()});addEventListener('wheel',fechar,{passive:true});
addEventListener('storage',e=>{if(e.key===KEY){try{M=JSON.parse(e.newValue)||{}}catch(x){M={}}M.uf=M.uf||{};M.mun=M.mun||{};aplicarTudo()}});
setTimeout(aplicarTudo,800);})();

/* Seccionais da OAB no modo OAB (specs docs/specs/master-seccionais-oab.md; desenho 05, Perfis 1.2, VS 21.10.26,
   L116-127 e L902). Um marcador por seccional visível no centroide da UF (d3.geoCentroid; a posição exata da sede mora
   no mapa-brasil.html do protótipo, que não veio: padrão adotado, a confirmar com o dono), na cor da marca. Botão
   direito no estado: o menu da divulgação, com os seis status do desenho, a paleta de cores do mapa e a data (hoje se
   não mudar). Depois de gravar, avisa o painel (window.parent) para recarregar; o painel avisa de volta
   (window.__bjSeccionaisAtualizar) quando a visibilidade muda. */
(()=>{if(!MODO_OAB)return;
const STATUS=['env','vis','neg','cli','ret','sem'],mk=document.getElementById('mk'),uZ=document.getElementById('uZoom'),secG=zoomG.append('g').attr('id','secMk');let SEC={};
const corValida=c=>/^#[0-9a-f]{6}$/i.test(c||'')?c:null;
const dataBr=d=>{const p=String(d||'').split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:''};
const hoje=()=>{const d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')};
const titulo=u=>{const s=SEC[u],m=s.marca,base={uf:u,estado:NOME[u]};return m?T('master.mapa_brasil.sec_titulo_marca',Object.assign(base,{status:m.rotulo,data:dataBr(m.data)})):T('master.mapa_brasil.sec_titulo',base)};
window.__secCorUf=u=>{const s=SEC[u];return s&&s.visivel&&s.marca?corValida(s.marca.cor):null};
const raio=()=>{const m=(uZ.getAttribute('transform')||'').match(/scale\(([\d.]+)/);return 5/(m?+m[1]:1)};
function desenhar(){secG.selectAll('*').remove();secG.style('display',atual?'none':null);if(!features||!path)return;const proj=path.projection();
 features.filter(f=>SEC[uf(f)]&&SEC[uf(f)].visivel).forEach(f=>{const u=uf(f),p=proj(d3.geoCentroid(f));if(!p)return;
  secG.append('circle').datum({seccional:u}).attr('class','sec-mk').attr('cx',p[0]).attr('cy',p[1]).attr('r',raio()).style('fill',window.__secCorUf(u))
   .on('mousemove',e=>mostrarTip(e,titulo(u))).on('mouseleave',()=>tip.style.opacity=0)
   .on('click',e=>{e.stopPropagation();abrir(f)})
   .on('contextmenu',e=>{e.preventDefault();e.stopPropagation();abrirMenu(u,e)});});
 if(window.__mkAplicar)window.__mkAplicar();}
const carregar=()=>fetch(CFG.seccionais,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.ok?r.json():Promise.reject(r.status))
 .then(j=>{SEC={};(j.seccionais||[]).forEach(s=>SEC[s.uf]=s);desenhar()}).catch(()=>{});
window.__bjSeccionaisAtualizar=carregar;
const avisarPainel=()=>{try{if(window.parent&&window.parent!==window&&typeof window.parent.__bjSeccionaisRecarregar==='function')window.parent.__bjSeccionaisRecarregar()}catch(e){}};
function registrar(u,sel,dv){const msg=dv.querySelector('[data-dv-msg]'),travar=on=>dv.querySelectorAll('button,input').forEach(b=>b.disabled=on);travar(true);msg.className='m';msg.textContent=T('master.mapa_brasil.registrando');
 fetch(CFG.divulgar.replace('/XX/','/'+u+'/'),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':CFG.csrfSeccionais},
  body:JSON.stringify({status:sel.st,cor:sel.cor,data:dv.querySelector('[data-dv-data]').value})})
 .then(r=>r.json().catch(()=>null).then(j=>({r,j}))).then(({r,j})=>{if(!r.redirected&&r.ok&&j&&j.mensagem){msg.className='m ok';msg.textContent=j.mensagem;avisarPainel();carregar();setTimeout(()=>{if(mk.contains(dv))mk.style.display='none'},1200);return}throw (j&&!r.ok&&j.erro)||null})
 .catch(erro=>{msg.className='m erro';msg.textContent=typeof erro==='string'?erro:T('master.mapa_brasil.erro_registrar');travar(false);dv.querySelector('[data-dv-ok]').disabled=!sel.st});}
function menuHtml(u,sel){const m=SEC[u].marca;
 return '<div class="t">'+escH(T('master.mapa_brasil.sec_nome',{uf:u,estado:NOME[u]}))+'</div><div class="dv"><div class="s">'+escH(T('master.mapa_brasil.registrar'))+'</div>'
  +'<div class="b">'+STATUS.map(k=>'<button type="button" data-st="'+k+'" class="'+(sel.st===k?'on':'')+'">'+escH(T('master.seccionais.status.'+k))+'</button>').join('')+'</div>'
  +'<div class="g">'+CORES.map(c=>'<button type="button" data-c="'+c+'" title="'+c+'" class="'+(c===sel.cor?'on':'')+'" style="background:'+c+'"></button>').join('')+'</div>'
  +'<input type="date" aria-label="'+escH(T('master.mapa_brasil.data'))+'" value="'+escH(m&&m.data?m.data:hoje())+'" max="'+hoje()+'" data-dv-data>'
  +'<button type="button" class="x" data-dv-ok'+(sel.st?'':' disabled')+'>'+escH(T('master.mapa_brasil.registrar_botao'))+'</button><div class="m" data-dv-msg>'+escH(titulo(u))+'</div></div>'}
function abrirMenu(u,e){if(!SEC[u])return;tip.style.opacity=0;const m=SEC[u].marca,sel={st:m?m.status:null,cor:m&&corValida(m.cor)?m.cor:CORES[0]};
 mk.innerHTML=menuHtml(u,sel);const dv=mk.querySelector('.dv'),marcar=(attr,v)=>dv.querySelectorAll('['+attr+']').forEach(b=>b.classList.toggle('on',b.getAttribute(attr)===v));
 dv.querySelectorAll('[data-st]').forEach(b=>b.onclick=ev=>{ev.stopPropagation();sel.st=b.dataset.st;marcar('data-st',sel.st);dv.querySelector('[data-dv-ok]').disabled=false});
 dv.querySelectorAll('[data-c]').forEach(b=>b.onclick=ev=>{ev.stopPropagation();sel.cor=b.dataset.c;marcar('data-c',sel.cor)});
 dv.querySelector('[data-dv-ok]').onclick=ev=>{ev.stopPropagation();if(sel.st)registrar(u,sel,dv)};
 mk.style.display='block';const w=mk.offsetWidth,hh=mk.offsetHeight;mk.style.left=Math.max(6,Math.min(innerWidth-w-6,e.clientX))+'px';mk.style.top=Math.max(6,Math.min(innerHeight-hh-6,e.clientY))+'px'}
document.addEventListener('contextmenu',e=>{const p=e.target.closest&&e.target.closest('path.uf');if(!p)return;e.preventDefault();e.stopPropagation();abrirMenu(uf(d3.select(p).datum()),e)},true);
new MutationObserver(desenhar).observe(estG.node(),{childList:true});
new MutationObserver(desenhar).observe(document.getElementById('painel'),{attributes:true,attributeFilter:['class']});
new MutationObserver(()=>secG.selectAll('circle').attr('r',raio())).observe(uZ,{attributes:true,attributeFilter:['transform']});
carregar();})();
