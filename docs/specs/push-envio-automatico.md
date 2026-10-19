# Painel do Push, parte D: envio automático ao cliente (P4d)

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
