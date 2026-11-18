# BlueJus I.A. liberada por indicação

**Risco:** MÉDIO (cota de uso por pessoa, recompensa por indicação) · **Frente:** `ia-liberada-por-indicacao` ·
**Data:** 18/11/2026 · **Origem:** pedido do Farlei; decisões do Samuel em
`bluejus-coordenacao/decisoes-ia-indicacao-1811.md`.

## Estado da frente

- 18/11: spec e levantamento das funções com custo (seção 9). **Sem código até a Q1 (seção 2) ser decidida.**
- Migration: provavelmente nenhuma (seção 5). Se a Q1 pedir uma tabela, o número é pedido ao Coordenador.

## 1. As regras decididas (Samuel, 18/11)

| # | Regra |
|---|---|
| D1 | A I.A. só volta quando o colega convidado **se cadastra**. Mandar o convite não libera nada. |
| D2 | A trava vem ao completar **3 peças**. Conta por peça gerada, não por tokens nem por custo. |
| D3 | Cada colega cadastrado, com **e-mail confirmado**, libera **+3 peças**. |
| D4 | A contagem é **por pessoa**, não por escritório. |
| D5 | As 3 peças livres **não renovam** por mês. |
| D6 | Escritório com **plano pago não trava**. |
| D7 | Por ora a regra vale só para a **I.A. que gera peças**. As outras funções vão para o Farlei decidir (seção 9). |

## 2. Q1, a pergunta que trava a implementação: o que é "peça gerada"?

**Hoje nenhuma I.A. do BlueJus redige peça.** Conferido no código (18/11):

- Os 7 agentes e os 17 comandos da pasta (`Inteligencia/Enum/Agente.php`, `Comando.php`) analisam, resumem e fazem
  relatórios. Nenhum redige petição, contestação, recurso ou minuta.
- O Peticionar é o editor manual de peças. Ele não chama a I.A., e o desenho `07 - Peticionar 1.0` não tem botão de I.A.
- "Usar modelo na pasta" (`UsarModeloNaPastaUseCase`) gera a peça a partir de um modelo, **sem I.A.**
- O único lugar onde a pessoa consegue uma peça escrita pela I.A. é o **Chat I.A.**: ela pede "redija uma contestação"
  e recebe o texto. Para o sistema, essa resposta é igual a qualquer outra do chat.

Então a D7 não tem onde valer sem uma escolha:

| Opção | O que conta como 1 peça | A favor | Contra |
|---|---|---|---|
| **A (recomendada)** | Criar o botão **"Redigir com a I.A."** no Peticionar (a I.A. escreve o rascunho da peça na pasta). Conta 1 peça por rascunho entregue. As outras funções seguem como hoje. | Segue a D7 ao pé da letra. A contagem é exata e fácil de explicar ("3 peças"). | É uma função nova, fora do desenho: precisa do "sim" do Samuel e de prompt jurídico revisado por advogado. São mais 2 ou 3 PRs. |
| B | Cada resposta do **Chat I.A.** e cada análise da pasta pedida pela pessoa conta 1. | Já existe, sai rápido. | 3 respostas acabam em minutos: na prática a I.A. trava logo no primeiro uso. Não é "peça". |
| C | Só os **relatórios** da pasta (comandos `relatorio_*`, `briefing`, `resumo_cliente`) contam 1. | Já existe e gera documento. | Relatório não é peça. O chat continua livre, e é onde a peça sai de verdade. |

**Recomendação:** A. Até a função existir, nada trava. As 3 peças, a trava e o convite ficam prontos, e passam a valer
no dia em que "Redigir com a I.A." for ao ar. Se o Farlei quiser travar antes, a opção C é a menos ruim.

O resto da spec vale para qualquer opção. Onde muda, está marcado **[Q1]**.

## 3. Onde contar a peça

- **Ponto único:** o fim, com sucesso, da geração.
  - Na opção A: quando o rascunho é gravado na pasta (documento da categoria PECA, como faz o `UsarModeloNaPastaUseCase`).
  - Na B ou C: o `ProcessarAnaliseDeInteligenciaHandler` ao concluir a análise, ou o `TransmitirRespostaDoChatUseCase`
    ao fechar a resposta.
- **Falha não conta.** Erro do provedor, recusa ou resposta vazia não gastam peça.
- **Quem pediu** é quem gasta. Geração automática (resumo automático do Push, título do chat) nunca conta.
- **Escritório pago não conta** (D6): a peça gerada lá não entra no saldo da pessoa.
- **Leitura, sem tabela nova:** o saldo é calculado.
  - Usadas = peças com sucesso pedidas pela pessoa em escritório sem plano pago. Na opção A, vem das análises com o
    tipo novo `peca` em `inteligencia_analise` (já guarda quem pediu, o escritório e o status). **[Q1]**
  - Direito = 3 + 3 × colegas que ela indicou e que se cadastraram com e-mail confirmado (seção 6).
  - Saldo = direito − usadas.

## 4. A trava

- **Onde barra:** no `DisponibilidadeDeInteligencia::para(user, tenant)`, que já é o portão de toda a I.A. Entra uma
  checagem nova, só para a ação que gera peça **[Q1]**: escritório sem plano pago e saldo da pessoa igual a zero.
  O pedido é recusado **antes** de chamar o provedor e antes de entrar na fila.
- **Plano pago (D6):** o plano vigente do escritório (`EspacoDoEscritorio`, mantido pelo `PlanoVigenteDoEscritorio`) é
  diferente do Free. O limite próprio do Master não conta como plano pago.
- **Ordem:** as travas que já existem vêm primeiro (I.A. desligada, aceite, liberação do Master, limite diário e mensal,
  orçamento em US$). Esta vem depois: a pessoa só vê "convide um colega" quando é isso que de fato a libera.

### Tela da trava (não há desenho: segue a convenção do sistema)

1. **Contador antes da trava:** junto do botão que gera a peça, o texto "Peças com I.A.: 2 de 3". Some em escritório pago.
2. **Ao travar:** o botão continua visível. No clique, abre a janela (componente de janela do sistema):
   - Título: "Suas peças gratuitas acabaram"
   - Texto: "Você usou as 3 peças com I.A. Convide um colega: quando ele se cadastrar e confirmar o e-mail, você ganha
     mais 3 peças."
   - Botões, nesta ordem: "Copiar meu link", "Enviar pelo WhatsApp", "Convidar por e-mail" (abre a janela "Indicar o
     BlueJus" que já existe) e "Fechar".
   - Linha de baixo, só para o administrador: "Com um plano pago, o escritório usa a I.A. sem esse limite." com link
     para o Contribua.
3. **Resposta do servidor:** o endpoint responde 403 com `{"motivo": "ia_peca_sem_saldo", "saldo": 0}`, e a tela abre a
   janela a partir disso. Sem nova rota.

## 5. O convite

Reaproveita o que existe (spec `indicar-bluejus.md`): código por pessoa (`CodigoDeIndicacao`), link curto `/i/{codigo}`,
canais (link, WhatsApp, e-mail) e a janela "Indicar o BlueJus".

- **Cota de e-mail da Hostinger:** o convite por e-mail já tem limite por pessoa (`IndicacaoConfig`: 20 por dia e 200 por
  30 dias, ajustável pelo Master) e o alerta de disparo alto (30 em 24h). A janela da trava põe **link e WhatsApp
  primeiro**, porque não gastam e-mail.
- **Proposta:** baixar o padrão de convites por e-mail para **5 por dia** por pessoa enquanto a campanha durar. É só um
  valor no painel do Master, sem código.
- Sem migration: as tabelas `indicacao`, `indicacao_codigo` e `indicacao_config` já cobrem tudo.

## 6. A liberação

- **Gatilho:** o mesmo do bônus de espaço (spec `bonus-de-espaco-por-indicacao.md`, regra 2). Conta a `Indicacao` do
  cadastro (`por_cadastro = true`, `indicado_user_id` = a conta nova) quando o e-mail da conta nova está confirmado.
  Os eventos são `EmailDaContaConfirmado` e `ContaCriadaPeloCadastro` (conta que já nasce confirmada, pelo Google).
- **Calculada, não gravada:** o direito da seção 3 é lido dessas linhas. Não há crédito para gravar, desfazer nem duplicar.
- **Aviso a quem indicou:** notificação dentro do sistema: "Fulano se cadastrou pelo seu convite: você ganhou mais 3
  peças com I.A." Sem e-mail, para poupar a cota.
- **Uma vez por conta nova:** a indicação do cadastro é única por conta (`indicado_user_id`). A auto-indicação já é
  recusada (`AtribuirCadastroPeloLinkUseCase`).

## 7. Riscos

1. **Link plantado (agora vale a pena):** a spec `indicar-bluejus.md` registrou que o `GET /i/{codigo}` pode ser chamado
   por outra página (uma imagem escondida, por exemplo) e gravar o código na sessão de quem visita. "Só vira risco se a
   indicação passar a dar recompensa." **Esta frente dá recompensa a quem indica.** Correção proposta, no mesmo PR da
   liberação: o cadastro mostra "Indicado por NOME" com um "Remover", e a indicação só conta se o cadastro foi enviado
   com o código visível no formulário.
2. **Contas falsas:** alguém cria contas com e-mails próprios para ganhar peças. O que segura: o e-mail confirmado, o
   limite de convites e a pausa por pessoa que o Master já tem (`IndicacaoPausa`). Aceito como no bônus de espaço.
3. **Pessoa em dois escritórios:** o saldo é da pessoa (D4). Peça gerada em escritório pago não gasta saldo.

## 8. Testes (quando implementar)

- Unit do saldo: 3 livres; +3 por indicado confirmado; indicado sem e-mail confirmado não conta; peça com falha não
  conta; peça em escritório pago não conta.
- Unit do portão: escritório Free com saldo zero recusa só a ação de peça **[Q1]**; plano pago libera; as travas antigas
  vêm antes.
- Funcional: a 4ª peça responde 403 com `ia_peca_sem_saldo`; um colega cadastra e confirma o e-mail e a 4ª passa; o
  contador mostra "N de M".
- Funcional do risco 1: código plantado na sessão e removido no formulário não gera indicação.

## 9. Levantamento para o Farlei: outras funções com custo

Hoje o único serviço de I.A. pago é o Claude (Anthropic). Não há OCR, transcrição de áudio nem serviço pago de consulta
a processos. "Limite do escritório" é o que já existe: limite diário e mensal de análises e orçamento mensal em dólar,
que o Master define.

### Com custo de I.A. (pedidas pela pessoa)

| Função | O que já limita hoje |
|---|---|
| Resumir com I.A. (Push) | limite do escritório e limite por pessoa por minuto |
| Agentes da pasta e relatórios (7 agentes, 17 comandos) | limite do escritório e limite por pessoa por minuto |
| Ler documentos da pasta com I.A. (a mais cara: manda o PDF inteiro) | limite do escritório, até 3 arquivos e 100 páginas |
| Perguntar sobre a pasta | limite do escritório e limite por pessoa por minuto |
| BlueJus I.A. na Central | limite do escritório e limite por pessoa por minuto |
| Sugerir agrupamento ao importar pastas em lote | limite do escritório |
| Chat I.A. (página inteira, aceita anexos) | 200 mil tokens por pessoa por dia e respostas curtas |

### Com custo de I.A. (automáticas)

| Função | O que já limita hoje |
|---|---|
| Resumo automático do Push quando chega publicação ("Avisar quando chegar") | limite do escritório |
| Título automático das conversas do chat | nenhum (custo muito baixo, modelo mais barato) |

### Sem I.A., com outro custo

| Função | Custo |
|---|---|
| Cópia de segurança no Cloudflare R2 | armazenamento pago, por volume |
| E-mails (convites, campanhas, resumo semanal ao cliente, avisos do Push) | cota diária da Hostinger |
| Busca de publicações (DJEN) e de processos (Datajud) | gratuitos (CNJ) |
| Sincronizar com o Google Drive | gratuito (cota da Google) |
| Exportar peça em PDF ou Word | gratuito (feito no próprio servidor) |

### Furo encontrado no levantamento

O **Chat I.A.** é barrado quando o escritório chega ao limite, mas **não entra na conta**: as respostas do chat não somam
no limite diário e mensal nem no orçamento em dólar do escritório. Hoje só o teto de 200 mil tokens por pessoa por dia
segura o chat. Vale corrigir à parte, com ou sem esta frente.

### Perguntas ao Farlei

1. Alguma dessas funções deve entrar na regra "3 grátis, depois indica"? A recomendação é começar só pela peça e olhar o
   uso por um mês.
2. O furo do Chat I.A. pode ser corrigido já (passar a contar no limite do escritório)?
