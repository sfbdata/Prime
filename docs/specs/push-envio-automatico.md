# Painel do Push, parte D: envio automático ao cliente (P4d)

## Estado da frente (sessão bluejus-0d; atualizado 19/10/2026, noite)

Releia esta seção antes de continuar se a sessão for compactada.

**Pedido do dono (19/10):** implementar na íntegra o desenho das abas Processo e Push Processual (02 - EXPEDIENTES,
versão completa de 18/10 em `docs/design/redesign-19-10-26/`, fora do git), mesmo que precise de frente nova.

**PRs e ordem de merge (fila de migrations do coordenador):**
- #55 link externo do Push: MERGEADO.
- #68 `pasta-ocultar-aba`: base master. Migration Version20261019200000 (renomear na vez: depois de #56, #60, #65,
  #69, #64, #74, #62, #83, #84).
- #75 `relatorio-processos-controladoria`: base master. Migration Version20261019233000.
- #82 `push-painel-monitoramento` (P4a): base master. Migration Version20261019234500.
- #87 `push-avisar-quando-chegar` (P4b): EMPILHADO sobre o #82, pronto. Migration Version20261020090000. A 5d
  (comercial-1a) empilha sobre ele (descadastro em Shared).
- #91 `processo-apensados` (P4f): base master. Migration Version20261020103000.
- #98 `push-avisar-cliente` (P4c): EMPILHADO sobre o #87. Migration Version20261020110000.
- P4d `push-envio-automatico`: EMPILHADO sobre o #98. Migration Version20261020121500. D6/D7 a confirmar com o dono.
- P4d (envio automático ao cliente, pelo Carteiro da 5d) e P4e (arquivos, OCR, análise completa): specs no
  scratchpad; P4e espera o #76 (composer) e aviso de Dockerfile.
Depois de cada merge: trazer o origin/master, migrar o banco de teste da frente (APP_ENV=test, TEST_TOKEN da frente),
suíte, push. Renomear a migration na vez dela.

**Decisões do dono (19/10/2026):** Controladoria = pessoas marcadas (só Master/primário marca); ocultar aba organiza a
pasta e não restringe o sistema; renovação do link por aviso no sino e link curto ativo; envios automáticos ao cliente
nascem desligados; pausar monitoramento para só a busca por número (legenda com OAB); chave "Análise da BlueJus IA"
do P4b nasce desligada. Coordenador: prazo da previsão pela lei (Lei 11.419 art. 4º, CPC art. 224); e-mail com
descadastro; sem Controladoria envia e avisa o primário; remetente nao-responda@ com reply-to do responsável; OCR no
P4e; "Apensado · Outro" para as 4 pastas antigas com 2 processos.

**Contratos com outras sessões:** security.yaml com uma rota pública por linha; framework.yaml com limitadores em
trechos próprios; descadastro (`App\Shared\Email\Descadastro`, ?Tenant, todos, reativar, listar) usado pela 5d e
pela a7; Carteiro da 5d (`App\Comercial\Envio`, commit 80c4c7de) para P4c/P4d; user_tenant: 7d põe `situacao`, #75
põe `controladoria`.

**Para o dono:** merges na ordem da fila; confirmar que o MAILER_DSN de produção sai pela Hostinger (SPF/DKIM do
nao-responda@); configurar o cron mensal do relatório (DEPLOY.md, #75); reconstruir a imagem de produção quando o P4e
entrar (tesseract/poppler); trocar o comando do worker para consumir a fila 'comercial' (PR da 5d) antes do P4c/P4d
enviarem pelo Carteiro.

**Próximo passo:** abrir o PR do P4f; fechar o P4c (revisão, visual, PR); depois P4d; P4e quando o #76 entrar.


Empilhada sobre `push-avisar-cliente` (#98). Desenho: `docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS
18.10.26.dc.html` (fora do git), bloco "Envio automático" do cartão "Avisar cliente" (1.2.3: L2678-2687, `mailVals`
L5014); README do handoff L243 (o servidor acrescenta o CC também nos automáticos). Risco MÉDIO (e-mail automático
para fora do escritório).

## Decisões

- D1 (dono, 19/10): os três envios automáticos nascem DESLIGADOS; o escritório liga por cliente. Desvio do desenho
  aprovado (no desenho "Novas movimentações" nasce ligado).
- D2 (coordenador): todo e-mail ao cliente leva o descadastro (#87); sem Controladoria envia e avisa o primário;
  remetente "{Escritório} via BlueJus", Responder para = responsável da pasta.
- D3 (contrato com a 5d): Carteiro, tipo LISTA, lista `push_cliente`, escopo = o escritório, origem
  `pasta:push_cliente_auto`. O Carteiro relê o descadastro antes de mandar e tem vazão própria. Enquanto o Carteiro
  não estiver no master, a interface `EnvioDeEmailAoClienteInterface` do #98 (provisória pelo Mailer) atende; o tipo
  Lista e a lista vão nos campos já espelhados.
- D4 (orquestrador): segredo de justiça (`nivelSigilo > 0`) não leva conteúdo da movimentação ao cliente: o e-mail diz
  "Há movimentação nova no seu processo sob segredo de justiça. Fale com o escritório." (mesma regra do P4b).
- D5 (orquestrador): a "Análise da BlueJus IA" fica fixa como "Só manual" (desenho), sem switch.
- D6 (orquestrador, revisão do P4d, 19/10): "assim que ela chega". O envio da captação só leva publicações com
  `dataDisponibilizacao` de hoje e dos 2 dias anteriores (relógio do PHP); a publicação antiga que a captação acabou de
  achar (pasta nova, processo recém-cadastrado) não vai como novidade. Ela aparece no resumo semanal se for da semana
  anterior.
- D7 (orquestrador, revisão do P4d, 19/10; a confirmar com o dono: menos e-mail é o lado seguro): nada sai, na captação
  nem no resumo, de processo com o monitoramento pausado (`!monitoramentoAtivo()`) ou baixado (`dataBaixa`), nem de
  pasta que não está ativa (arquivada, cancelada, suspensa) ou está trancada.
- Notas (revisão do P4d): processo sem sigilo informado (`nivelSigilo` nulo) é tratado como público. O filtro
  "Audiências e intimações" pega quase tudo, porque o DJEN marca quase toda comunicação como "Intimação"; é o
  comportamento do desenho ("Avisa quando chegar intimação ou designação de audiência").
- Robustez (revisão do P4d): na captação, o envio ao cliente roda ANTES do aviso à equipe (uma falha do P4b que feche o
  EntityManager não deixa o cliente sem nada). Cada destinatário fica gravado no histórico (flush) logo depois que o
  e-mail dele sai; qualquer erro de um destinatário vai ao log e não impede os outros; a falha de uma pasta não para as
  outras do escritório. O resumo não vai de novo a quem já recebeu o da semana, e cada movimentação aparece uma vez
  por cliente (o mesmo processo em duas pastas dele).

## 1. Modelo

As preferências são POR CLIENTE (desenho: `reg.auto` no registro do cliente): `cliente_envio_automatico` (tenant,
cliente UNIQUE, novas_movimentacoes, audiencias_intimacoes, resumo_semanal — bool, padrão false — atualizado_por,
atualizado_em). O histórico usa o `envio_ao_cliente` do #98 com `tipo = auto` e a descrição ("Envio automático:
movimentação de dd/mm", "…: intimação de dd/mm", "…: resumo semanal").

## 2. Tela (bloco "Envio automático" do cartão)

Rótulos e dicas exatos: "Novas movimentações" ("Envia ao cliente um aviso com o texto oficial da movimentação, assim
que ela chega"), "Audiências e intimações" ("Avisa quando chegar intimação ou designação de audiência"), "Resumo
semanal" ("Toda segunda-feira, um resumo das movimentações da semana anterior"); linha fixa "Análise da BlueJus IA"
com o selo roxo de cadeado "Só manual" e a dica "A análise depende de correção e revisão do advogado antes de chegar
ao cliente". Sem nenhum e-mail ativo: os switches esmaecidos com o prefixo "Cadastre um e-mail para ativar. " na dica,
e o clique abre o formulário de cadastro (desenho). `POST /pasta/{id}/push/cliente/automatico` com `chave` e `ligado`
(estado desejado), CSRF, EDIT na pasta.

## 3. Disparo

- Novas movimentações / audiências e intimações: ao fim de cada captação (o mesmo ponto único do aviso do P4b, uma vez
  por execução), para cada pasta com publicação nova → cliente principal → se a chave estiver ligada: um e-mail por
  e-mail ativo do cliente (não descadastrado) com as movimentações novas daquela pasta. "Audiências e intimações"
  filtra por tipo (intimação, ou texto com designação de audiência); se as duas chaves estiverem ligadas, a mesma
  movimentação não sai duas vezes. Conteúdo: tipo, data, órgão e o texto oficial (teor sem HTML, até 4.000
  caracteres, com "texto completo disponível com o escritório" se cortado); segredo de justiça pela D4.
- Resumo semanal: comando `app:push:resumo-semanal-cliente` (todos os escritórios, `--tenant`, `--dry-run`, flock),
  segunda-feira, com as movimentações da semana anterior (seg a dom) de cada pasta cujo cliente tem a chave ligada; sem
  movimentação na semana, não envia. Linha de cron documentada no `DEPLOY.md` (configurar é do dono).
- CC da Controladoria na 1ª mensagem de cada envio (como no #98); sem Controladoria, envia e avisa o primário uma vez
  por execução (não por e-mail).
- Falha no envio nunca derruba a captação nem o comando.
- Não repetir: grava a movimentação já avisada por cliente (`referencia` no histórico) e não manda de novo.

## 4. Testes mínimos

Unit: padrões desligados, filtro de audiências/intimações, não repetir, segredo de justiça, resumo da semana anterior.
Functional: rota das chaves (CSRF, EDIT, outro tenant, sem e-mail), captação com dublê dispara o e-mail certo só com a
chave ligada, descadastrado não recebe, CC na 1ª, comando semanal com `--dry-run` e relógio controlado.
