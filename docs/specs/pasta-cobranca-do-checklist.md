# Pasta: cobrança automática do checklist de documentos (desenho 02, aba Documentos)

Frente `pasta-exito-cobranca`, sessão f6, aberta em 19/10/2026 a pedido do coordenador. Risco BAIXO
(notificação e estado operacional, sem dinheiro nem identidade). Desenho:
`docs/design/redesign-19-10-26/02 - EXPEDIENTES - VS 18.10.26.dc.html` (dc), linhas 2229, 2245, 2311,
4230-4372, 5494-5498 e 6740-6779.

## Estado da frente (atualizar a cada passo)

- **Documentos (esta spec): em implementação.** Branch `pasta-exito-cobranca`, a partir do origin/master.
- **Financeiro (Êxito e Sucumbência, fatia 11b): TRAVADO** pelo dono. As specs da 11b dizem "não começar
  antes da resposta" (`docs/specs/engineering-loop/inventario-financeiro-push.md:193`). O coordenador levou ao
  dono: base do % (soma dos lançamentos, como no desenho e no §3.9 do Farlei, contra o "Honorários
  contratuais" editável já decidido) e, para confirmar, congelar o valor ao marcar recebido e deixar o previsto
  fora de contratado, recebido e vencidos (dentro do extrato e do "Copiar resumo"). Nada do Financeiro começa
  antes da resposta.
- **D-DOC-S6 ("adiar: sem cobrança") foi SUPERADA** pela regra do dono de 19/10 ("tudo que está no desenho
  entra"), registrada pelo coordenador.
- Migration: `Version20261020138200` (provisória), na FIM da fila (depois do cadeado da b5); renomear na vez.
- Revisão (19/10): aprovada com ressalvas, corrigidas: rotina pasta a pasta (carrega, grava e solta), comando
  que reabre o EntityManager depois de erro de banco, arquivada e cancelada fora (convenção das listas do
  Expediente; o desenho é omisso), responsável só com vínculo ativo no escritório, lembrete por dia e não por
  segundo, pendência da aba com o texto literal do desenho ("item marcado sem anexo").

**Para o dono (só ele decide):**
1. A data da previsão "pelo Chat I.A" (decisão 4).
2. Volume do primeiro dia: a primeira rodada audita todo item já marcado sem anexo (aviso "A pasta será auditada"
   por item) e passa a cobrar toda pasta com procuração ou contrato pendente. Ligar o cron com um escritório
   piloto, ou em data combinada?
3. Cron em horário de Brasília fixado na linha (`CRON_TZ=America/Sao_Paulo`; sem suporte, `0 12` UTC), não
   no relógio da VPS (coordenador, 19/10). Para o piloto, o comando tem `--escritorio=ID` e `--simular`.
4. A contagem "pendente há N dias" começa na primeira rodada (o item não guarda a data de criação), como no
   desenho.

## Decisões (coordenador, 19/10)

| # | Ponto | Decisão |
|---|---|---|
| 1 | Controladoria (cópia no 4º aviso, previsão descumprida, auditoria "sem anexo", desativar) | `App\Pasta\Service\ControladoriaDoEscritorioInterface` (cópia idêntica da do #98, para o merge não conflitar). Hoje a implementação provisória devolve ninguém; quando o #75 entrar, a troca é só de implementação. Não empilhar sobre o #75 |
| 2 | Dia útil | `ListarDatasDoSistemaUseCase` da Agenda (fonte única de datas, já cruza os feriados do Ponto): não envia em sábado, domingo nem feriado nacional/estadual ou do escritório. Não ler o Ponto direto |
| 3 | Textos | Literais do desenho, inclusive o tom que sobe a cada aviso |
| 4 | "Responda com a data aqui no Chat I.A" | **Com o dono.** A previsão fica modelada e com UseCase próprio (isolado), sem entrada na tela. A resposta pelo Chat depende da frente de Inteligência (4b) e do "Encaminhar via Chat I.A" (5d). O texto da cobrança termina com a pergunta do desenho |
| 5 | "BlueJus IA" nos textos de desativar | Mantém a decisão S-4 já registrada (sugestões são regras sobre catálogo, não IA): o conteúdo do desenho volta (cobrança, auditoria e alerta pausados; lembrete de 30 dias), sem atribuir à IA |

## O que o desenho faz e como entra

### Cobrança dos pendentes (`ckCobPlano` + `cobrarChecklist`, dc 4278-4372)
- **Quem recebe:** o responsável da pasta (`Pasta::responsavel`). Pasta sem responsável ativo: não cobra.
- **Canal:** só aviso dentro do sistema (sino e Central). Não há e-mail nem WhatsApp no desenho.
- **Peso do item** (`CK_PESO`, dc 4173): procuração ou contrato = 3; hipossuficiência, identidade, CPF ou RG
  = 2; o resto = 1; +1 se pendente há 7 dias ou mais. **Urgente** = peso ≥ 3 ou previsão descumprida.
- **Plano do dia** (só em dia útil): urgentes todo dia; não urgentes, um por dia na pasta e com intervalo de
  2 dias por item; **teto de 2 cobranças por dia por pasta**; item com previsão ainda no futuro fica pausado.
  Ordem: maior peso, depois o cobrado há mais tempo.
- **Textos por nível** (`ckCobMsgBase`, dc 4339-4347), com a pergunta final (`ckCobMsg`, dc 4300-4306) e o
  texto próprio da previsão descumprida.
- **Coordenação:** cópia no 4º aviso e a cada 3 depois (4, 7, 10...) e em previsão descumprida, com os textos
  "Documento sem retorno: X" / "Previsão descumprida: X". Nunca para o próprio responsável.
- **Registro por item:** desde quando está pendente, datas dos envios, previsão, quantas previsões quebradas.
  Item concluído sai da cobrança; voltar a pendente recomeça do zero (como o desenho apaga o registro).

### Auditoria "sem anexo" (`ckAuditar`, dc 4314-4337)
- Item marcado como entregue sem arquivo correspondente (`ConferenciaDeAnexosDoChecklist`, regra já existente):
  avisa quem marcou ("Item marcado sem anexo: X") e a controladoria ("Pasta N para auditoria"), **uma vez por
  item** enquanto continuar assim. Anexar o documento ou desmarcar limpa.
- Dispara ao marcar (quem marcou) e na varredura diária (itens marcados antes, atribuídos ao responsável).

### Checklist desativado (dc 2229, 2245, 4247-4248, 4270-4276)
- Ao desativar: a controladoria é avisada ("Checklist desativado: Pasta N", com o motivo e as pendências).
- A cada 30 dias desativado: o responsável recebe "Revisar checklist da Pasta N" (tipo Providência).
- Desativado pausa cobrança, auditoria e o alerta da aba. Reativar zera o lembrete.

### Na tela
- Selo por item (dc 2311, 6745-6749): pendente cobrado → "cobrado Nx" (dica com as datas dos envios); âmbar
  `#a8661a`, vermelho `#c0392b` a partir de 3 envios. O "sem anexo" existente continua.
- Pendências da aba (dc 5494-5498): "N documento(s) cobrado(s) e ainda não anexado(s)" junto do "sem anexo".
- Textos de desativar e desativado com o conteúdo do desenho (decisão 5).
- A faixa "Cobrança automática ativa..." (`ckCob`, dc 6760-6779) é calculada no desenho mas **não é desenhada
  no HTML**: fica fora (onde o desenho é omisso, não se inventa tela).

### Fora (sem dono ou dependente de outra frente)
- Registro em "providências" (`C.provRegistrar`): não existe no sistema.
- Resposta da previsão pelo Chat I.A (decisão 4).

## Modelo

- `pasta_checklist_cobranca` (nova, TenantAware): `tenant_id`, `item_id` (único, FK com CASCADE),
  `pendente_desde` (date), `envios` (json, lista de datas), `previsao` (date null), `previsao_informada_em`,
  `quebras` (int), `auditoria_em` (date null), `auditoria_por_id` (FK user, SET NULL).
- `pasta.checklist_lembrete_em` (datetime null): último lembrete de 30 dias.
- Tipos de aviso (string, sem migration): `checklist_cobranca` ("Cobrança de documento", grupo urgente) e
  `checklist_providencia` ("Providência"), mapeados na Central.

## Execução

- Comando diário `app:pasta:cobrar-checklist` (cron de dia de semana às 9h no `DEPLOY.md`): por escritório,
  confere o dia útil; cobra os pendentes, varre a auditoria e manda os lembretes de 30 dias. Idempotente no
  dia (o envio de hoje fica registrado; rodar de novo não repete).
- Tempo e datas pela hora do PHP (America/Sao_Paulo), nunca `NOW()` do Postgres.

## Testes

Unit do plano (pesos, teto, intervalo, urgente, previsão, níveis e cópia da coordenação) e functional do
comando (dia útil e feriado, idempotência, isolamento entre escritórios), da auditoria ao marcar, do aviso ao
desativar, do lembrete de 30 dias, do selo e das pendências da aba.
