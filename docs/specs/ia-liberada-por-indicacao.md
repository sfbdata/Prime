# BlueJus I.A. liberada por indicação

**Risco:** MÉDIO (cota de uso por pessoa, recompensa por indicação) · **Frente:** `ia-liberada-por-indicacao` ·
**Data:** 18/11/2026 · **Origem:** pedido do Farlei ("limite de consumo de tokens equivalente a 3 peças"); decisões
do Samuel em `bluejus-coordenacao/decisoes-ia-indicacao-1811.md`.

## Estado da frente

- 18/11: spec, medição do uso real e levantamento das funções com custo (seção 10). Q1 decidida: medir por consumo.
- Branch única, PR #769 em rascunho. Vai ao ar junto com os outros pedidos do Farlei, sem vigia.
- Migration: nenhuma prevista (seção 4). Se aparecer, o número é pedido ao Coordenador.

## 1. As regras

| # | Regra | Quem decidiu |
|---|---|---|
| D1 | A I.A. só volta quando o colega convidado **se cadastra**. Mandar o convite não libera nada. | Samuel, 18/11 |
| D2 | Mede por **consumo**: soma os tokens que a pessoa gastou e trava ao chegar no equivalente a **3 peças**. | Samuel, 18/11 (Q1) |
| D3 | Cada colega cadastrado, com **e-mail confirmado**, libera **mais o mesmo tanto** (3 peças). | Samuel, 18/11 |
| D4 | A contagem é **por pessoa**, não por escritório. | Samuel, 18/11 |
| D5 | O consumo grátis **não renova** por mês. | Samuel, 18/11 |
| D6 | Escritório com **plano pago não trava**. | Samuel, 18/11 |
| D7 | Entra na soma **toda a I.A. pedida pela pessoa**: Chat I.A., análises e agentes da pasta, Central. | Samuel, 18/11 (Q1) |
| D8 | A pessoa vê uma **barra de uso**. | Samuel, 18/11 |
| D9 | Corrigir o achado do link `/i/{codigo}` (seção 8). | Samuel, 18/11 |

## 2. Quanto é "3 peças"

### O que o uso real mostra (produção, 18/11, só leitura)

O piloto ainda usa pouco e sempre no modelo Haiku 5.5:

| Uso | Vezes | Tokens de entrada (média) | Tokens de saída (média) | Total por uso (média) | Maior |
|---|---|---|---|---|---|
| Análises e agentes da pasta | 8 | 5.794 | 6.092 | 11.886 | 17.343 |
| Respostas do Chat I.A. | 8 | 4.905 | 652 | 5.557 | 7.717 |

Nenhuma resposta do chat em produção foi peça (nenhuma traz "Excelentíssimo"). A peça é estimada a partir disto.

### A estimativa

Uma peça (petição inicial, contestação ou recurso de tamanho comum, de 8 a 12 páginas) sai pelo Chat I.A. em umas 4
respostas, porque cada resposta vai até 2.000 tokens (`CHAT_IA_TETO_TOKENS_RESPOSTA`):

- Saída: cerca de 7.000 tokens (o texto da peça, de 5 a 6 mil palavras somando os ajustes).
- Entrada: cerca de 26.000 tokens (o contexto da pasta, uns 5 mil por resposta, e o histórico da conversa, que cresce a
  cada resposta).
- **1 peça ≈ 33.000 tokens.** É o mesmo que umas 3 análises da pasta ou 6 respostas curtas do chat.

**3 peças = 100.000 tokens** (arredondado). Cada colega cadastrado libera mais 100.000.

Custo para o BlueJus, pelo preço de tabela que o sistema já usa (`ModeloDeIa::preco`), com 3/4 de entrada:

| Modelo | 100 mil tokens custam |
|---|---|
| Haiku 5.5 (o de hoje) | cerca de US$ 0,02 |
| Sonnet 5.5 | cerca de US$ 0,40 |
| Opus 5.5 | cerca de US$ 0,80 |

Os três números (100.000 de franquia, 100.000 por colega, 33.000 por peça para a barra) ficam em constantes nomeadas
num lugar só. Mudar é trocar o número, sem migration.

**Cuidado:** "Ler documentos" manda os PDFs inteiros. Uma leitura de 100 páginas passa fácil de 100 mil tokens e gasta
a franquia inteira de uma vez. A barra mostra isso, e a janela de "Ler documentos" passa a avisar "Esta leitura pode
usar boa parte da sua I.A. gratuita" quando o escritório não tem plano pago.

## 3. O que entra na soma

Soma `tokens_entrada + tokens_saida` de tudo o que a pessoa pediu, em escritório sem plano pago:

| De onde | Como achar a pessoa | Já gravado em |
|---|---|---|
| Análises, agentes, comandos e relatórios da pasta; Perguntar sobre a pasta; Ler documentos; Resumir com I.A. (Push); BlueJus I.A. da Central; Sugerir agrupamento do lote | `solicitante_id` | `inteligencia_analise` |
| Chat I.A. (página inteira), inclusive resposta interrompida ou refeita, porque o gasto aconteceu | `chat_ia_conversa.usuario_id` | `chat_ia_mensagem` (papel `assistente`) |
| Resumo automático do Push ("Avisar quando chegar") | o responsável da pasta, que é quem ligou o aviso | `inteligencia_analise` |

Fica de fora: o título automático das conversas (até 24 tokens, não é gravado) e o teste de conexão do console.

- **Escritório pago (D6):** o plano vigente (`EspacoDoEscritorio`, mantido pelo `PlanoVigenteDoEscritorio`) é diferente
  do Free. O limite próprio do Master não conta como plano pago. A soma só olha o consumo feito em escritório que está
  sem plano pago **hoje**: se o escritório passar a pagar, o que a pessoa gastou lá deixa de contar.
- **Pessoa em dois escritórios grátis:** soma os dois (D4).

## 4. O saldo (calculado, sem tabela nova)

- **Direito** = 100.000 + 100.000 × colegas que a pessoa indicou, cadastrados e com e-mail confirmado (seção 7).
- **Usado** = soma da seção 3.
- **Saldo** = direito − usado.

Um serviço novo no domínio Inteligencia, `SaldoDeIaDaPessoa`, faz a conta. As duas somas usam os índices que já
existem (`idx_inteligencia_analise_solicitante`; no chat, a conversa da pessoa). O número de colegas vem do domínio
Indicacao por um método público (o Inteligencia não lê as tabelas da Indicacao).

## 5. A trava

- **Onde:** no `DisponibilidadeDeInteligencia::para(user, tenant)`, que já é o portão de toda I.A. pedida pela pessoa
  (Chat I.A., análises, Central, agrupamento, resumo do Push). Entra o caso novo `Disponibilidade::SaldoDaPessoaEsgotado`,
  conferido **depois** das travas que já existem (I.A. desligada, aceite, liberação do Master, limite diário e mensal do
  escritório, orçamento em dólar). Assim a pessoa só vê "convide um colega" quando é isso que de fato a libera.
- **Quando:** antes de chamar a I.A. e antes de entrar na fila. O `paraProcessamento` (o worker) não confere o saldo:
  o pedido já foi aceito.
- **Passar do limite:** um pedido aceito com saldo positivo termina, mesmo que passe do limite (a resposta do chat não é
  cortada no meio). O próximo pedido é que trava.
- **Resumo automático do Push:** com o saldo do responsável zerado, o resumo não é gerado e o aviso da publicação sai sem
  ele, como já acontece hoje quando a I.A. está indisponível.

## 6. A barra de uso e a janela da trava

Não há desenho. Segue a convenção do sistema e reaproveita a barra de "espaço usado" do Contribua.

### Barra de uso

- **Onde:** no topo do Chat I.A., na janela de I.A. da pasta e na BlueJus I.A. da Central. **Some em escritório pago.**
- **Texto:** "I.A. gratuita: 1,4 de 3 peças usadas" (com 1 colega cadastrado: "de 6 peças"). A conta em peças é
  tokens usados ÷ 33.000, com uma casa decimal.
- **Link ao lado:** "Ganhar mais", que abre a janela "Indicar o BlueJus".
- **Aviso:** a partir de 80% a barra fica amarela e o texto vira "Resta menos de 1 peça. Convide um colega para ganhar
  mais 3."

### Janela da trava

Abre quando a pessoa tenta usar a I.A. com o saldo zerado:

- Título: "Sua I.A. gratuita acabou"
- Texto: "Você usou o equivalente a 3 peças. Convide um colega: quando ele se cadastrar e confirmar o e-mail, você ganha
  mais 3."
- Botões, nesta ordem: "Copiar meu link", "Enviar pelo WhatsApp", "Convidar por e-mail" (abre a janela "Indicar o
  BlueJus" que já existe) e "Fechar".
- Só para o administrador, uma linha a mais: "Com um plano pago, o escritório usa a I.A. sem esse limite.", com link para
  o Contribua.
- **Resposta do servidor:** a mesma das outras recusas da I.A. (`InteligenciaIndisponivelException`): status 409 com
  `motivo: "saldo_da_pessoa_esgotado"` e a mensagem. A tela abre a janela quando vê esse motivo.

## 7. O convite e a liberação

Reaproveita o que existe (spec `indicar-bluejus.md`): código por pessoa, link curto `/i/{codigo}`, canais (link,
WhatsApp, e-mail) e a janela "Indicar o BlueJus".

- **Cota de e-mail da Hostinger:** o convite por e-mail já tem limite por pessoa (`IndicacaoConfig`: 20 por dia e 200
  por 30 dias, ajustável pelo Master) e o alerta de disparo alto (30 em 24h). A janela da trava põe **link e WhatsApp
  primeiro**, porque não gastam e-mail. Proposta: o Master baixa para **5 por dia** enquanto a campanha durar (só um valor
  no painel, sem código).
- **Quando libera:** o mesmo gatilho do bônus de espaço (spec `bonus-de-espaco-por-indicacao.md`, regra 2). Conta a
  indicação do cadastro (`por_cadastro = true`, `indicado_user_id` = a conta nova) quando o e-mail da conta nova está
  confirmado. Os eventos são `EmailDaContaConfirmado` e `ContaCriadaPeloCadastro` (conta que já nasce confirmada).
- **Calculada, não gravada:** o direito da seção 4 é lido dessas linhas. Não há crédito para gravar, desfazer nem
  duplicar. A indicação do cadastro é única por conta nova, e a auto-indicação já é recusada
  (`AtribuirCadastroPeloLinkUseCase`).
- **Aviso a quem indicou:** notificação dentro do sistema, sem e-mail para poupar a cota: "NOME se cadastrou pelo seu
  convite: você ganhou mais 3 peças na I.A."

## 8. Correção do link `/i/{codigo}` (D9)

**O problema.** A spec `indicar-bluejus.md` registrou que alguém pode plantar o próprio código na sessão de outra pessoa,
e que isso "só vira risco se a indicação passar a dar recompensa". Agora quem indica ganha I.A. Hoje:

- O `/i/{codigo}` já recusa imagem e iframe escondidos (`ehNavegacaoDePagina`, cabeçalhos `Sec-Fetch-*`). Basta, porém,
  um link disfarçado em que a pessoa clique para o código ficar na sessão dela.
- No "Criar conta", o cupom só vai no formulário quando está ligado, ou quando a pessoa desligou o cupom que veio na URL
  (`entrada.js`, linha 433). Se ela abre o cadastro depois, por outro caminho, o campo aparece vazio, o formulário não
  manda `indicacao` e o `CadastroPeloLinkListener` usa o código da sessão sem a pessoa saber.

**A correção.** A indicação só conta se a pessoa viu o código no formulário:

1. O servidor preenche o campo de cupom do "Criar conta" também com o código da sessão, não só com o da URL. O cupom
   aparece ligado, com o código à vista, e a pessoa pode desligar.
2. O formulário **sempre** manda `indicacao`: o código, se o cupom está ligado, ou vazio, se está desligado.
3. O `CadastroPeloLinkListener` deixa de usar a sessão como plano B: sem `indicacao` no corpo, não liga nada. A sessão
   continua servindo só para preencher o campo (passo 1) e é limpa no cadastro, como hoje.
4. A volta do Google e do Facebook já termina no mesmo formulário (o cupom guardado em `sessionStorage`). O passo 2 vale
   para ela também.

Testes: um código plantado na sessão e desligado no formulário não gera indicação; um formulário sem o campo
`indicacao` não gera indicação; o cupom da sessão aparece preenchido no "Criar conta".

## 9. Riscos aceitos

1. **Contas falsas:** alguém cria contas com e-mails próprios para ganhar I.A. O que segura: o e-mail confirmado, o limite
   de convites e a pausa por pessoa que o Master já tem (`IndicacaoPausa`). Cada conta falsa vale uns US$ 0,02 de I.A.
   no Haiku. Aceito como no bônus de espaço.
2. **Uma leitura grande passa do limite** (seção 5): só o próximo pedido trava.
3. **O furo do escritório continua:** o chat soma no saldo da pessoa (D7), mas segue fora do limite diário e mensal e do
   orçamento em dólar do **escritório** (seção 10). Corrigir à parte, se o Farlei quiser.
4. **A limpeza do chat devolve saldo:** a retenção das conversas do Chat I.A. apaga as mensagens antigas, e com elas
   some o consumo que contava no saldo. Quem usa o chat há muito tempo volta a ter um pouco de I.A. gratuita. Aceito: o
   efeito é pequeno e não pede tabela nova.
5. **O aviso ao indicador anda junto com o bônus de espaço:** a notificação "você ganhou mais 3 peças" sai no momento
   em que o bônus de espaço é concedido (uma vez por conta nova). Se o bônus não for concedido por outro motivo (ex.:
   falha ao gravar), o colega conta no saldo do mesmo jeito, só não há aviso.
6. **O saldo olha os vínculos ativos da pessoa:** a soma do consumo considera os escritórios em que ela está hoje; o
   uso feito num escritório que ela já deixou não entra.

## 10. Testes

- Unit do `SaldoDeIaDaPessoa`: franquia de 100.000; +100.000 por indicado confirmado; indicado sem e-mail confirmado não
  conta; o chat soma, inclusive a resposta interrompida; o consumo em escritório pago não soma; pessoa em dois
  escritórios grátis soma os dois.
- Unit do portão: saldo zerado em escritório Free devolve `SaldoDaPessoaEsgotado`; plano pago libera; as travas antigas
  vêm antes; `paraProcessamento` não olha o saldo.
- Funcional: com o saldo zerado, o chat e a análise da pasta respondem 403 com o motivo; um colega cadastra, confirma o
  e-mail e a I.A. volta; a barra mostra "N de M peças" e some em escritório pago; o resumo automático do Push não é
  gerado com o saldo zerado.
- Funcional do link (seção 8).

## 11. Levantamento para o Farlei: outras funções com custo

Hoje o único serviço de I.A. pago é o Claude (Anthropic). Não há OCR, transcrição de áudio nem consulta paga a processos.
"Limite do escritório" é o que já existe: limite diário e mensal de análises e orçamento mensal em dólar, que o Master
define. Com esta frente, **todas as funções de I.A. abaixo passam a gastar a franquia da pessoa**.

### Com custo de I.A., pedidas pela pessoa

| Função | O que já limita hoje |
|---|---|
| Resumir com I.A. (Push) | limite do escritório e limite por pessoa por minuto |
| Agentes da pasta e relatórios (7 agentes, 17 comandos) | limite do escritório e limite por pessoa por minuto |
| Ler documentos da pasta (a mais cara: manda o PDF inteiro) | limite do escritório, até 3 arquivos e 100 páginas |
| Perguntar sobre a pasta | limite do escritório e limite por pessoa por minuto |
| BlueJus I.A. na Central | limite do escritório e limite por pessoa por minuto |
| Sugerir agrupamento ao importar pastas em lote | limite do escritório |
| Chat I.A. (página inteira, aceita anexos) | 200 mil tokens por pessoa por dia e respostas curtas |

### Com custo de I.A., automáticas

| Função | O que já limita hoje |
|---|---|
| Resumo automático do Push quando chega publicação | limite do escritório |
| Título automático das conversas do chat | nenhum (custo muito baixo, modelo mais barato) |

### Sem I.A., com outro custo

| Função | Custo |
|---|---|
| Cópia de segurança no Cloudflare R2 | armazenamento pago, por volume |
| E-mails (convites, campanhas, resumo semanal ao cliente, avisos do Push) | cota diária da Hostinger |
| Busca de publicações (DJEN) e de processos (Datajud) | gratuitos (CNJ) |
| Sincronizar com o Google Drive | gratuito (cota da Google) |
| Exportar peça em PDF ou Word | gratuito (feito no próprio servidor) |

### Perguntas ao Farlei

1. O Chat I.A. deve passar a contar também no limite e no orçamento do **escritório**? Hoje ele é barrado quando o
   escritório chega ao limite, mas não soma nesse limite.
2. Os 100 mil tokens (3 peças) estão bons? Depois de um mês de uso a medição pode ser refeita.

## 12. Contratos da implementação

Nomes fixos, para as partes poderem ser feitas em paralelo.

- `App\Inteligencia\Enum\Disponibilidade::SaldoDaPessoaEsgotado = 'saldo_da_pessoa_esgotado'`. `ehLimiteDeCota()`
  continua falso para ele (status 409). `codigoDaRecusa()` devolve o próprio valor. `mensagem()`: "Sua I.A. gratuita
  acabou. Convide um colega para ganhar mais 3 peças."
- `App\Inteligencia\Service\Saldo\MedidaDoUsoDeIa` (interface, o ponto de extensão da seção 13): `unidade()`
  ('tokens' ou 'pecas'), `franquia()`, `porColega()` e `usado(User $user, array $tenantIdsSemPlanoPago)`. A de hoje é
  `ConsumoEmTokens` (`#[AsAlias]`), com `FRANQUIA_EM_TOKENS = 100_000` e `TOKENS_POR_COLEGA = 100_000`.
- `App\Inteligencia\Service\SaldoDeIaDaPessoa::de(User $user): SaldoDeIaOutput` recebe a medida e só faz a conta
  (direito = franquia + porColega × colegas), com `PECAS_DA_FRANQUIA = 3`. Em tokens, 1 peça = franquia ÷ 3.
- `App\Inteligencia\DTO\SaldoDeIaOutput` (readonly): `string $unidade`, `bool $seAplica` (a pessoa está em algum
  escritório sem plano pago), `int $tokensUsados`, `int $tokensDireito`, `float $pecasUsadas` (uma casa),
  `int $pecasDireito`, `int $percentual` (0 a 100), `bool $esgotado`, `bool $perto` (80% ou mais),
  `int $colegasCadastrados`.
- `DisponibilidadeDeInteligencia::para()` confere, por último, se o **escritório atual** não tem plano pago e o saldo da
  pessoa está esgotado.
- Contribua expõe `App\Contribua\Service\PlanoPagoDoEscritorio` com `temPlanoPago(int $tenantId): bool` e
  `semPlanoPago(array $tenantIds): array` (os ids sem plano pago). Sem linha de espaço = Free.
- Indicacao expõe `App\Indicacao\Service\ColegasCadastradosPorIndicacao::contar(int $indicadorId): int` (indicação
  `por_cadastro`, conta nova com `isEmailConfirmado()`).
- Twig: função `ia_saldo()` no `InteligenciaExtension`, devolvendo o `SaldoDeIaOutput` de quem está logado (nulo sem
  login). A barra é o parcial `inteligencia/_barra_de_uso.html.twig`; a janela da trava é
  `inteligencia/_janela_saldo_esgotado.html.twig`.
- Notificação ao indicador: tipo `ia_colega_cadastrado`, pelo `NotificacaoService::criarNotificacao`, disparada nos
  mesmos dois gatilhos do bônus de espaço, uma vez por conta nova.

## 13. Quando existir "Redigir peça com a I.A." (D10, Samuel, 18/11)

A trava liga agora por consumo (100 mil tokens). O mecanismo fica pronto para contar **peças** no dia em que a função
"Redigir peça com a I.A." existir, sem tela nova agora:

- **A regra futura:** 1 peça entregue = 1 unidade; trava em 3; cada colega cadastrado libera mais 3.
- **O ponto de extensão:** a interface `MedidaDoUsoDeIa` (seção 12). Basta criar `PecasEntregues implements
  MedidaDoUsoDeIa`, com `unidade() = 'pecas'`, `franquia() = 3`, `porColega() = 3` e `usado()` contando as peças
  entregues com sucesso pela pessoa nos escritórios sem plano pago (a análise do tipo novo `peca`, concluída, em
  `inteligencia_analise`), e mover o `#[AsAlias]` para ela.
- **O que não muda:** o portão, a conta do direito, a notificação, a barra e a janela. A barra já fala em peças, e com a
  unidade `pecas` mostra o número inteiro.
- **A decidir quando a função vier:** se a medida por peças **substitui** a de consumo (todo o resto da I.A. fica livre)
  ou se as duas valem juntas (uma trava para "Redigir peça" e outra para o resto). No segundo caso, o portão passa a
  receber a ação pedida para escolher a medida.
