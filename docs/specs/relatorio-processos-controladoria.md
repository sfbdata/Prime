# Relatório geral de processos e Controladoria (PR 3 de 3 da frente "Expediente: Processo e Push")

## Estado da frente (sessão bluejus-0d; atualizado 19/10/2026)

Releia esta seção antes de continuar se a sessão for compactada.

**Pedido do dono (19/10):** implementar na íntegra o desenho das abas Processo e Push Processual (02 - EXPEDIENTES),
mesmo que precise de frente nova.

**PRs abertos e ordem de merge (fila de migrations do coordenador):**
1. #55 `push-link-externo`: link externo do Push. Migration Version20261019183000. Entra depois do #52 (de outra
   sessão); o #77 (trusted proxies, b5) também entra antes por mexer no framework.yaml.
2. #68 `pasta-ocultar-aba`: EMPILHADO sobre o #55 (base push-link-externo). Migration Version20261019200000.
3. #75 `relatorio-processos-controladoria`: base master. Migration Version20261019235650 (renomeada na vez dela: era 20261019233000, depois 20261019235550).
4. #82 `push-painel-monitoramento` (P4a): base master. Migration Version20261019234500.
5. P4b `push-avisar-quando-chegar`: em implementação, EMPILHADO sobre o #82.
Depois de cada merge: trazer o origin/master para as branches que ficaram, testes, push. Renomear a migration para
o timestamp do momento se o coordenador pedir.

**Decisões do dono (19/10/2026):**
- Controladoria = pessoas marcadas no escritório (só Master/primário marca).
- Ocultar aba vale só na pasta; é para ORGANIZAR a pasta, não restringir acesso no sistema (módulo Push global,
  links já criados e metas em /tarefas continuam).
- Renovação do link externo por aviso no sino; link curto já ativo (sem "ativa com o domínio").
- Envios automáticos ao cliente nascem DESLIGADOS (P4d).
- Pausar monitoramento para só a busca por número; OAB continua; legenda "A busca pelo número do processo fica
  parada; intimações pela OAB continuam chegando.".
- (coordenador) Prazo da previsão conta da publicação (1º útil após a disponibilização), Lei 11.419 art. 4º e CPC
  art. 224; e-mail com "não quero mais receber"; sem Controladoria envia e avisa o primário; remetente nao-responda@
  com reply-to do responsável; P4e com OCR.

**Contratos com outras sessões:**
- security.yaml: cada rota pública em linha própria (f6 e a7 também acrescentam).
- framework.yaml: limitadores em trechos próprios (#77, #55, #68, #82).
- `email_descadastro` (Shared, P4b): interface pública reaproveitada pela a7 (vagas) e pela frente "Comercial e
  e-mails".
- user_tenant: a 7d põe `situacao`; o #75 põe `controladoria`. Colunas independentes.

**Pendências com o dono:** chave "Análise da BlueJus IA" do P4b (padrão desligado, a confirmar); MAILER_DSN de
produção pela Hostinger (SPF/DKIM); cron mensal do relatório (DEPLOY.md); SYMFONY_TRUSTED_PROXIES (achado ALTO,
tratado no #77).

**Próximo passo:** fechar o P4b (revisão, visual, PR); depois specs de P4c (e-mails do cliente + envio manual da
Análise IA com CC), P4d (envio automático), P4e (arquivos + OCR + análise completa; trazer antes o PR da 4b por
causa do composer). "Vincular apensado" e o filtro por processo esperam o zip completo do desenho de 18/10.


Desenho: `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html` (no checkout principal):
menu ⋮ do cabeçalho da aba Processo L1581 e `procHdMenu` L6588; item no menu do cartão L2930; análise
`spAnalisar` L4513-4537; relatório `spHTML` L4538-4555; envio `spEnviar` L4566-4568 e `semProcNotificar` em
`bluejus-central.js` L3225-3233; prévia `impPrev` L198-212 e `impVals` L4621-4631; aprendizado `spMarcar` L4507-4512.

Risco MÉDIO (flag nova no vínculo `UserTenant` e notificação a terceiros). Revisão de permissão obrigatória.

## Decisões

- D1 (dono, 19/10): **Controladoria = pessoas marcadas no escritório.** Coluna `user_tenant.controladoria` (bool,
  default false), marcada na tela de edição do vínculo (`app_tenant_user_edit_role`, mesma guarda dela), com o
  rótulo "Controladoria" e a ajuda "Recebe o relatório mensal de pastas sem processo e a cópia dos avisos à Controladoria.".
- D2 (orquestrador): PDF gerado no servidor (Dompdf, como `PastaResumoController`), não html2pdf no navegador.
  A prévia segue o padrão da impressão da pasta (`#psImpressao`/`pasta-impressao.js`): fragmento do servidor, opções
  "Cabeçalho do escritório" e "Tamanho do texto" (9 a 16 pt, passo 0,5), botões Imprimir, Baixar PDF, Copiar texto.
- D3 (orquestrador): o chat da BlueJus IA com ações não existe. "Enviar à Controladoria" vira **aviso no sino/Caixa da
  Central** (tipo `relatorio_sem_processo`) com link para o relatório; o texto do aviso é o do desenho, resumido.
- D4 (orquestrador): quem abre o relatório vê as pastas que pode VER (mesmo filtro de visibilidade da lista do
  Expediente). Master, primário e quem é Controladoria veem todas as pastas do escritório.
- D5 (orquestrador): envio automático por comando de console (padrão `SincronizarDjenCommand`: todos os tenants,
  `--tenant`, `--dry-run`, flock), agendado no cron da VPS diariamente às 10h05 de Brasília; o comando só envia na
  primeira segunda-feira do mês e uma vez por mês por escritório (histórico). A linha do cron vai documentada em
  `DEPLOY.md`; configurar o cron é do dono.

## 1. Modelo

- `user_tenant.controladoria` bool default false.
- `pasta_decisao_sem_processo` (`App\Pasta\Entity\DecisaoSemProcessoDaPasta`, TenantAware): pasta (FK cascade),
  decisao (`administrativo`|`precisa`), termos (json list<string>), por (FK user SET NULL), em. Uma por pasta (UNIQUE
  pasta_id, sobrescreve). Gravada pelo `DefinirPastaAdministrativaUseCase` quando a análise da pasta achou termos
  judiciais (L4510): marcar = `administrativo`, desmarcar = `precisa`.
- `relatorio_sem_processo_envio` (`App\Pasta\Entity\EnvioDoRelatorioSemProcesso`, TenantAware): mes (`MM/AAAA`),
  sem, deveria, rever, classificar, admin, em, automatico (bool). UNIQUE (tenant, mes) para os automáticos. Alimenta a
  tabela "Evolução" (últimos 6 + Hoje).

## 2. Análise (`App\Pasta\Service\AnalisadorDePastasSemProcesso`)

Porte fiel de `spAnalisar` (L4513-4537), em PHP, testado com os mesmos casos:
- Fontes por pasta, em lote (sem N+1): textos dos registros/mensagens (`PastaMensagem.conteudo` sem HTML), títulos e
  nomes dos documentos (+ categoria), títulos/descrições das metas, títulos do checklist. Consultas novas em lote
  (`IN (:ids)`, tenant pela pasta), no padrão de `PastaRepository::reusPorPasta`.
- `SP_JUD` (termo, regex, peso) e `SP_ADM` exatamente como L4504-4505; peso ×0,4 quando o termo aparece em 2+
  decisões `administrativo` do escritório (L4518-4520); `admScore = adm × 1,5`; `liq = score − admScore`.
- Categorias e textos de sugestão/confiança exatamente como L4525-4530 (`inconsistente`, `com`, `rever`, `admin`,
  `deveria`, `sugAdmin`, `classificar`); "marcada" = `Pasta::$administrativa`; "processo" = processo principal.
- Metas sugeridas e prazos em dias úteis (L4531), `motivoC`, `resumo` e `prox` (L4532-4535). "Parada há N dias":
  dias desde a última movimentação da pasta, se o sistema já expõe esse dado; senão fora (registrar).
- Só pastas ativas e não excluídas.

## 3. Relatório

- Rota `GET /expediente/relatorio-processos` `expediente_relatorio_processos` (página/fragmento) e
  `GET /expediente/relatorio-processos/pdf` `expediente_relatorio_processos_pdf`. Título "Relatório geral · Processos
  das pastas · dd/mm/aaaa". Conteúdo = `spHTML` (L4538-4555): cartões-resumo, "Resumo para despacho", parágrafo,
  "Evolução" (se houver histórico), seções com as colunas do desenho, selos de confiança, "Com processo vinculado",
  nota final. Rodapé do PDF "BlueJus · página k de n".
- Entradas: item "Relatório geral de processos" no ⋮ do cabeçalho da aba Processo (`_processos_vinculados.html.twig`
  L101-129, hoje só com "Marcar como administrativo") e no ⋮ de cada cartão de processo. Abre a prévia.

## 4. Enviar à Controladoria

- Item "Enviar à Controladoria agora" (`bi-send`) no mesmo ⋮. `POST /expediente/relatorio-processos/enviar`
  (CSRF), quem pode: quem tem EDIT na pasta de onde clicou (o desenho mostra o menu a quem edita).
- `EnviarRelatorioSemProcessoUseCase(tenant, quem, automatico)`: analisa, monta o resumo (L4565 `spResumo`) e:
  - avisa cada pessoa Controladoria e cada primário ativo (menos quem enviou): "Relatório de pastas sem processo
    (dd/mm/aaaa): X sem processo vinculado: Y deveriam ter, Z precisam de revisão, W sem classificação e V
    administrativas coerentes." + evolução desde o último envio ou "Primeiro envio.";
  - avisa cada responsável (menos quem enviou) com as pastas dele que pedem ação: "N pasta(s) sua(s) sem processo
    precisam de atenção: 1180, 1181…";
  - URL do aviso = o relatório. Tipo `relatorio_sem_processo` em `TiposDeAvisoDaCentral` (grupo `atencao`,
    rótulo "Relatório", ícone `bi-file-earmark-bar-graph`).
  - Sem nenhuma pessoa Controladoria: o aviso vai só aos primários e a resposta diz
    "Nenhum usuário com perfil de Controladoria cadastrado." (texto do desenho, L2718).
  - Manual não grava histórico (L4567); automático grava `relatorio_sem_processo_envio`.
- Resposta/aviso na tela: "Relatório enviado à Controladoria" (o desenho dizia "pelo Chat I.A"; D3).
- Título do aviso geral: "Relatório mensal: N pasta(s) sem processo", o do desenho (`semProcNotificar`
  L3231), também no envio manual: o relatório é o mesmo e o título é o que o desenho mostra. Decisão
  registrada na revisão de 19/10.
- Revisão de permissão (19/10): teto de 1 envio manual a cada 30 minutos por escritório (429, "O relatório
  já foi enviado há pouco. Tente de novo em alguns minutos."); só recebe aviso quem tem o módulo
  Expediente (Controladoria, primários e responsáveis); só Master ou primário marca "Controladoria".
- Comando `app:pastas:relatorio-sem-processo` (D5).

## 5. Fora deste PR

Painel lateral do Push (monitoramento, avisar cliente, cópia do e-mail ao cliente para a Controladoria, envio
automático): é a frente seguinte; a coluna `controladoria` já servirá a ela. Comando `/semprocesso` do chat.

## 6. Testes mínimos

Unit: analisador com os casos de cada categoria, regex CNJ, peso reduzido pelo aprendizado, metas em dias úteis;
UseCase de envio (destinatários, sem Controladoria, automático uma vez por mês, primeira segunda-feira).
Functional: relatório e PDF (200, visibilidade por usuário, outro tenant não aparece), envio (CSRF, sem EDIT 403,
avisos criados para as pessoas certas e não para quem enviou), marcação de Controladoria na tela de vínculo,
decisão gravada ao marcar/desmarcar administrativa, itens no ⋮. Comando com `--dry-run`.

## 7. Pendências

- Abas ocultas (PR #68, ainda não integrado): o relatório não respeita as abas ocultas de quem lê. Depois
  que os dois PRs entrarem no master, um ajuste pequeno no `ColetorDePastasSemProcesso` faz as fontes
  excluírem os registros e documentos das abas ocultas para o leitor.
