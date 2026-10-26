# Master: seção Desempenho (monitor de saúde)

Desenho: `docs/design/redesign-25-10-26/05 - Perfis 1.2 - VS 25.10.26.dc.html` (template L271-280, lógica `opsVals`
`desemp`, L939-961) e `bj-monitor.js`. Inventário do Perfis: `perfis-1.2-vs-25-10-metas-mapa-config.md`.
Risco: BAIXO (dado de plataforma, só Master, sem cobrança nem identidade). Frente: Desempenho.

## 1. O que o desenho pede

Seção "Desempenho" do painel `/master` (botão novo na barra de seções):

- Seletor de período: 24 horas, 7 dias, 30 dias (padrão 7) + botão "Limpar registros".
- 8 KPIs: Disponibilidade, Tempo de carregamento (P95), Erros no sistema, Falhas de login, Falhas de upload,
  Último backup, Teste de restauração, CPU e memória do servidor (sempre "Sem dados" até haver API de métricas).
- Cartões: Incidentes (abrir, resolver), Backups e restauração (registrar backup, registrar teste), Integrações
  (situação), Erros recentes (12 últimos).
- Cores e limiares dos KPIs estão no JS do desenho (disp < 99 vermelho; P95 > 3000 vermelho, > 1500 âmbar; login > 10
  vermelho; backup > 26 h vermelho; teste falhou vermelho). Valem como regra.

## 2. Decisões (o desenho é do protótipo, o real grava no servidor)

- D1. O protótipo mede no navegador e guarda em `localStorage`. No sistema real o dado vai ao servidor, global
  (sem tenant, como `RecursoEstado`), para o Master ver o que todos os usuários viveram.
- D2. Eventos medidos nas telas (`erro`, `carga`, `ping`, `upload`) chegam por `POST /monitor/evento` (qualquer usuário
  logado, CSRF, limite de taxa, corpo ≤ 1 KB, `msg` ≤ 160, página só o caminho sem query string). Falha de login
  é gravada no servidor pelo listener de `LoginFailureEvent` (e sucesso como `login ok`), sem depender do navegador.
- D3. Incidentes e registros de backup/teste são digitados pelo Master (mesmas perguntas do desenho, mas em modal,
  não `prompt()`). Auditados (`Auditavel`).
- D4. Integrações: situação real = credencial configurada (Google, Facebook, Anthropic, e-mail, banco/PIX,
  tribunais/DJEN). Rótulos e ícones do desenho; "Conectada" / "Não configurada".
- D5. CPU e memória: "Sem dados" com a legenda do desenho. Não inventar métrica.
- D6. "Limpar registros" apaga só os eventos (não incidentes nem backups), com confirmação. Retenção: 90 dias,
  purga por comando agendado (entra no scheduler em PR próprio, arquivo quente).
- D7. A mensagem de erro de JS é texto de fora: sempre `textContent`, nunca `innerHTML`.

## 2.1 Dado pessoal (LGPD)

- Guarda só `usuario_id` (nulo se anônimo; vira nulo se o usuário for excluído). NÃO guarda IP, user agent, e-mail
  digitado nem corpo de requisição; falha de login com e-mail desconhecido entra sem usuário.
- `pagina` é só o caminho, sem query string; `mensagem` truncada em 160 e sem dado de formulário.
- Retenção 90 dias, purga diária por comando (nasce no PR 2, agendado no PR 5) e "Limpar registros" do Master; o PR 2
  só vai a produção com o comando pronto. Incidentes e backups não têm dado pessoal além de quem registrou.

## 3. Modelo

- `desempenho_evento` (id, tipo [erro|carga|ping|login|upload], ok bool null, ms int null, mensagem varchar(160) null,
  origem varchar(60) null, pagina varchar(120) null, usuario_id null SET NULL, em timestamp). Índice (tipo, em).
- `desempenho_incidente` (id, titulo, categoria, gravidade [crit|alta|media|baixa], aberto_em, resolvido_em null,
  solucao null, aberto_por_id).
- `desempenho_backup` (id, tipo [backup|restauracao], ok, observacao, registrado_em, registrado_por_id).

## 4. Entregas (PRs pequenos, nesta ordem)

1. Modelo + migration + `ConsultarDesempenhoUseCase` (KPIs, limiares) + `GET /master/dados/desempenho?periodo=`.
2. Captura: `POST /monitor/evento`, listener de login, `bj-monitor.js` real (base.html.twig, quente).
3. Seção na tela (`_desempenho.html.twig` + `desempenho.js` + CSS `.dp-*`) com os 4 cartões e o seletor.
4. Incidentes e backups (modais, POST, auditoria) + Limpar registros.
5. Purga agendada (scheduler).

## 5. Testes

Unit do UseCase (limiares, "Sem dados", janela de período, P95). Functional: acesso só ROLE_SUPER_ADMIN (403 para
os outros), POST de evento rejeita corpo grande/tipo inválido, login falho gera evento, limpar não apaga incidente.
Tela: arranjo por filho direto dos KPIs e dos 4 cartões, `.card-outline` ausente.
