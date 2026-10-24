# Spec: Trabalho Externo, desenho 24.10.26

Desenho: `docs/design/redesign-24-10-26/06 - Trabalho Externo -24.10.26.dc.html` (1428 linhas).
Anterior: `docs/design/trabalho-externo/vs-18-10-26/` (spec `trabalho-externo-vs-18-10-26.md`).
Risco: BAIXO (domínio TrabalhoExterno). Linhas "l." são do desenho novo.

## Estado da frente (atualizado em 24/10/2026)

Três PRs, nesta ordem (decisão do coordenador, 24/10):

1. **Tela sem banco** (`trabalho-externo-24-10-26`): D2, D5, D6 (parte), D7, D9, D10, D11a, D12, L1.
2. **Despacho** (D8), migration `Version20261020152100`.
3. **Recusa de oferta e expiradas** (D4, D11b), migration `Version20261020152200`.

**Decisões** (coordenador, 24/10): prazo do despacho pula também os feriados do sistema (P5);
vaga expirada esconde os selos AO VIVO e NOVA (P6); a recusa não zera em "Ofertar novamente" e o
aviso prometido vai ao sino do dono da vaga e da Central (P7); a imagem de `uploads/` fica de fora.

**Decisões do dono** (via coordenador, 24/10, todas as recomendações):
- P1: sai o cartão "Minhas candidaturas" e a frase "Acompanhe em ... Minhas candidaturas" do toast
  da candidatura (PR 1). O externo vê "Candidatura enviada" no cartão da vaga.
- P2: mapa do Google em iframe como no desenho, com `maps.google.com` liberado no frame (PR 1).
- P3: o corte de 30 dias das expiradas vale para todos, inclusive o Master (PR 3).
- P4: "Recusar" usa o diálogo de confirmação da tela, com o texto do desenho (PR 3); o toast do
  despacho diz "O solicitante vê o despacho no Resumo e recebe um aviso." (PR 2).

Fora desta frente, sem mudança no desenho: Termo de Voluntariado (parado no jurídico), "Salvar
edição"/"Novo escritório" do SOLICITANTE, toast "Nova vaga" entre abas. Moldura do protótipo
ignorada: cabeçalho, NAV e links `.dc.html` (D3), scripts `bj-*` (o `bj-tema.js` já existe como
`tema-comemorativo.js`), `localStorage`/evento `storage`, dados de exemplo e código calculado sem uso.

## Diferenças do desenho e o que fazer

### D2. Keyframes (l.36 a 40), PR 1
`teRadar` (scale .2/opacity .9 → scale 1.6/opacity 0), `teParc` (translateY 0 → -5px → 0),
`tePt` (opacity 0 em 0%/100%, 1 em 30% a 70%), `teBarra` (translateX -110% → 260%), `teStamp`
(opacity 0, `translate(-50%,-50%) rotate(-14deg) scale(1.8)` → opacity 1, scale 1). Em
`trabalho-externo.css`.

### D4. Vaga expirada, PR 3
Cartão com `position:relative; overflow:hidden`. Quando expirada:
- véu `inset:0; z-index:2; background:rgba(243,246,248,.62); backdrop-filter:grayscale(.9)`, sem clique;
- carimbo "EXPIRADA": `z-index:3; left:50%; top:46%; transform:translate(-50%,-50%) rotate(-14deg);
  border:3px solid #a3232b; color:#a3232b; border-radius:6px; padding:4px 16px;
  font:bold 22px/1 Arial,Helvetica,sans-serif; letter-spacing:4px; background:rgba(255,255,255,.55);
  animation:teStamp .45s cubic-bezier(.3,1.6,.5,1) both`;
- faixa no rodapé "Expirou em dd/mm/aaaa": `z-index:3; margin:2px -18px -16px; padding:7px 12px;
  background:#fdf1f2; border-top:1px solid #f3c9cf; color:#842029; 11.5px bold; centro`;
- selo "Expirada" em `#6b8494`; sem cronômetro; sem AO VIVO e NOVA (P6).
- Clique do externo não abre: toast "Vaga expirada" / "As inscrições desta vaga se encerraram em
  {dd/mm/aaaa}. Ative os alertas para não perder as próximas." O interno abre normalmente.

Regra da data (`expDe`, l.1124), a primeira que valer: oferta com `expira` vencido e situação aberta,
encerrada ou expirada → `expira`; `inscricoesAte` vencido e situação não pendente nem reprovada →
`inscricoesAte` 23:59:59; encerrada ou expirada → `encerradaEm || expira || em`.
Externo vê as expiradas há até 30 dias e não pendentes/reprovadas; expirada há mais de 30 dias sai
da lista (alcance pendente em P3). Dado novo: `Vaga.encerradaEm`.

### D5. Meu currículo compacto (l.221 a 231), PR 1
- Cabeçalho: título `15px bold; white-space:nowrap; flex:1`; sai "Ver e baixar"; botões em
  `display:flex; gap:6px`: "Editar" (`bi-pencil`) e **"Word"** (`bi-file-earmark-word`). Botão:
  `height:32px; padding:0 12px; border:1px solid #d3dde4; border-radius:2px; background:#fff;
  color:#34505f; 13px`; hover `border-color:#0a6fa0; color:#0a6fa0; background:#e5f1f7`.
- Corpo: `display:flex; align-items:center; gap:14px`; avatar 52px fonte 18px, sem a sombra; nome
  `display:block; 16px bold`; linha `13px #4a6373` "{cargo} · {OAB}". **Saem** Instagram, linhas de
  cargo/OAB/local/áreas/pretensão/voluntário/arquivo e "Atualizado em".
- Barra: bloco `margin-top:14px`; trilho `6px #e6edf2 raio 3px`; barra `transition:width .6s`;
  texto **"Falta: {lista}"** em `#7a5200`, `margin-top:6px`.
- Botão do termo depois da barra, `margin-top:12px; width:100%; border-radius:2px`, sem hover (só
  aparece quando o Termo existir).
- Vazio: `padding:10px 0 4px`; texto `#4a6373` "Cadastre seu currículo uma vez e use para se
  candidatar às vagas publicadas na Central."; botão "Cadastrar currículo" `height:38px;
  padding:0 18px; border-radius:2px`, sem hover nem transição.
- "Word": baixa `Currículo - {nome}.doc` (`application/msword`, BOM + o mesmo HTML do documento).
  Rota nova no `CurriculosController`, só para o próprio currículo.

### D6. Prévia do currículo (l.232 a 239), PR 1
Cartão `data-screen-label="Meu currículo formatado"`: caixa `#fff; border:1px solid #dde5eb;
border-radius:4px; overflow:hidden`; cabeçalho `flex; gap:10px; flex-wrap:wrap; padding:14px 18px;
border-bottom:1px solid #eef2f5`, `bi-file-earmark-person` #0a6fa0 18px, "Prévia do currículo"
15px bold.
- Com currículo: botão "Baixar PDF" (`bi-download`) `height:32px; padding:0 14px; border:none;
  border-radius:2px; #0a6fa0; #fff; 13px bold`, hover `#0b5f86` (usa a rota PDF que já existe);
  corpo `#e9eef2; padding:16px` centrado com iframe `srcdoc` do documento, `title="Meu currículo"`,
  `width:100%; max-width:794px; aspect-ratio:210/297; border:none; background:#fff;
  box-shadow:0 4px 18px rgba(18,36,47,.12)`.
- Sem currículo: esqueleto A4 decorativo (`max-width:420px; aspect-ratio:210/297;
  grid-template-columns:34% 1fr`, lateral `#0a6fa0`, círculo 56px `rgba(255,255,255,.35)`, barras
  de 6px `#c9d6df`, `#9fc3d8`, `#e3e9ee`, `#dbe5ec`) e "Cadastre seu currículo para vê-lo
  formatado aqui." 13px #4a6373.
- Sai o cartão "Minhas candidaturas" e a frase do toast da candidatura (P1).

### D7. Documento do currículo na tela, PR 1
Em `curriculo_documento.html.twig`, antes do `@media print`: `@media screen{html,body{overflow-x:hidden;
margin:0}.pg{width:100%;max-width:210mm;min-height:0;aspect-ratio:210/297;margin:0 auto}}
@media screen and (max-width:640px){.pg{grid-template-columns:1fr}.sd{padding:8mm 6mm}
.mn{padding:8mm 6mm}.hd h1{font-size:18pt}}` (adaptado às classes do documento do sistema).

### D8. DESPACHO no Resumo (l.512 a 530), PR 2
Detalhado na revisão do PR 2. Resumo: só o Master despacha; natureza (5 opções que desmarcam),
prazo (padrão hoje + 5 dias úteis, pulando feriados, P5), texto até 5.000 com contador, anexos
reais, "Despachar"; mensagem de sistema "Novo despacho da Central BlueJus{ · natureza}."; resposta
até 3.000 quando "Aceitar resposta" está ligada; lista numerada com natureza, prazo, anexos, local
por extenso e assinatura eletrônica com código; respostas recuadas em verde; Extrato para impressão.
Dado novo: tabela de despacho e `DemandaExterna.respostasAoDespachoLiberadas`.

### D9. Animação depois de enviar a solicitação (l.737 a 767), PR 1
Só no Enviar (Rascunho continua com "Rascunho salvo"); o toast "Solicitação enviada" deixa de
aparecer. Fases: `busca` na hora (som busca), `parc` em 2300 ms (som busca), `ok` em 5200 ms (som ok).
Fundo `fixed; inset:0; z-index:9700; rgba(11,53,80,.45)`, clique fecha e cancela os temporizadores.
Diálogo `z-index:9701; width:min(520px,100%); height:min(560px,92vh); border-radius:8px;
box-shadow:0 24px 60px rgba(11,53,80,.4); background:#dfe7ec`. Mapa (P2): iframe `https://maps.google.com/maps?q={cidade} {UF} Brasil&z=12&output=embed`, `left:-60px; top:-170px; width:calc(100% + 120px); height:calc(100% + 340px); filter:saturate(.85); pointer-events:none`.
Fechar "x" 34px redondo `rgba(255,255,255,.92)`, `right:10px; top:10px`, hover #fff/#0a6fa0.
Gradiente `linear-gradient(180deg, rgba(11,53,80,.15), rgba(11,53,80,0) 30%, rgba(11,53,80,0) 55%,
rgba(255,255,255,.9) 78%)`. Radar, barra, avatares, textos ("Localizando correspondentes em {cidade
ou 'sua cidade'}{ · UF}", "Enviando {serviço} para a Central BlueJus…", "Procurando parceiro",
"Verificando quem atende {serviço ou 'o serviço'} na região…"), fase ok ("Solicitação recebida e em
análise", "{ID} chegou à Central BlueJus. Você recebe um aviso assim que a proposta estiver
pronta.", "Fechar", "Acompanhar solicitação") e os sons (Web Audio) com os valores da l.886 a 901.

### D10. Aviso de andamento às partes, PR 1
Toda mudança de situação ("Andamento: {situação}{ · motivo}") e mensagem de sistema, arquivo ou
andamento avisam solicitante e, se houver responsável ou convite ativo, o convidado; quem agiu e
Master/Equipe ficam de fora. Tipo novo de `Notificacao` (`trabalho_externo_demanda_andamento`, sem
migration: coluna string), título "{ID} · {serviço ou 'Trabalho externo'}", ação "Abrir a demanda".
Na tela aberta da Central: toque `tocarAlerta` (triangular 880 Hz em 0 s e 1174.66 Hz em .13 s,
ganho .18 em 15 ms, queda até .45 s) e toast "Andamento · {ID}" (texto cortado em 110, hora HH:MM,
"Abrir conversa"), respeitando a preferência "Sons de alerta" do perfil.

### D11. Rodapé do detalhe da vaga para o externo
- a) PR 1: vaga própria aberta mostra "Fechar" e "Sua vaga · acompanhe os candidatos acima"
  (`bi-people`, primário, só fecha).
- b) PR 3: "Recusar" (`bi-x-circle`, secundário, `#a3232b`) ao lado de "Aceitar oferta";
  confirmação "Recusar esta oferta? O escritório e a Central BlueJus serão avisados."; histórico
  `bi-x-circle #a3232b` "Oferta recusada por {quem}"; toast "Oferta recusada" / "Obrigado por
  responder. Você continua recebendo novas oportunidades."; depois disso "Você recusou · aceitar
  mesmo assim" (`bi-arrow-counterclockwise`, `#1e7b45`). Aviso no sino ao dono da vaga e à Central
  (P7). Dado novo: recusas por vaga e usuário.

### D12. Menu ⋮ do Master, VAGAS E CURRÍCULOS, PR 1
Primeiro item: "Alertas de vagas ativados" (`bi-bell`, com ✓) / "Alertas de vagas desativados"
(`bi-bell-slash`); alterna `ConfiguracaoCentral.vagas.sino`; toast com o título do novo estado e
"Ninguém recebe o sininho nem o aviso de vaga nova até você ativar de novo." / "Os usuários voltam
a receber o sininho e o aviso de vaga nova."

### L1. Lacuna antiga achada, PR 1
Dica do "selecionar todas" do Chat: "Desmarcar todas" (l.1356) no lugar de "Limpar seleção".

## Conferência
Cada PR fecha com o checklist ✓/✗ dos seus itens acima, linha a linha, contra Twig + CSS + JS.
