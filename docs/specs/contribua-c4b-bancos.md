# Contribua C4b/C4c: bancos Inter, Banco do Brasil e "Outro" na Integração bancária

**Risco:** MÉDIO (credenciais bancárias, dinheiro) · **Frente:** `contribua-bancos` (sessão 9b) · **Data:** 19/10/2026

## Estado da frente

- 19/10: spec. Empilhada sobre `contribua-pix` (#88, que está sobre o #95), declarada; decisão do coordenador. A `contribua-pix` está
  atrás da `contribua-c3` e se atualiza quando #95 e #88 entrarem no master. A frente era da 4b (parada); a 0d confirmou que não começou nada.
- Migration: uma, no fim da fila; timestamp do momento, renomeada na vez.
- 19/10: **implementada** (base `ClientePixBacen`, `BancoInter`, `BancoDoBrasil`, `BancoPixPadrao`, campos extras, .pfx com senha,
  webhook BACEN, `app:contribua:conciliar`). Revisão aprovada com ressalvas, **ressalvas corrigidas**: cliente HTTP dos bancos sem
  logger e sem redirecionamento (`ClienteHttpDosBancos`); credenciais só do banco configurado; segredo exigido de novo ao trocar de
  banco ou de URL do "Outro"; URLs do "Outro" sem rede interna; CONCLUIDA sem valor legível não confirma; bases do BB trocadas para
  `api-pix.bb.com.br` / `api-pix.hm.bb.com.br`. Migration `Version20261021160000` (renomeada: o 235500 é do #90) no fim da fila (depois da `Version20261019230000`
  da C4). **Falta:** push e PR (empilhado sobre o #88).
- 20/10: Inter com a conta corrente opcional (`conta_corrente`, na mesma migration; cabeçalho `x-conta-corrente`, só números e sem
  zeros à esquerda, em toda chamada à API Pix e não no token, como a documentação do Inter pede; trocar de banco a apaga) e valor
  pago da consulta BACEN = soma de `pix[].valor` (um item ilegível zera a soma e a cobrança não confirma; `pagaEm` do `pix[0]`).

## O pedido

Coordenador: "os bancos Inter, Banco do Brasil e 'Outro' no seletor de 'Integração bancária' do Master. Estão no desenho, então entram de
verdade, não como 'em breve'. Etapas C4b e C4c." Desenho: `docs/design/redesign-19-10-26/08 - Contribua -  VS 18.10.26.dc.html` L80-102
(marcação) e L227, L249-256 (lógica). Specs de referência nesta branch: `docs/specs/contribua-c4-cora.md` e `docs/specs/contribua-planos-cora.md` (D9).

## Contrato herdado (não muda)

- Interface `App\Contribua\Banco\BancoPix` (`codigo()`, `testarConexao()`, `criarCobranca(PedidoDeCobrancaPix)`, `consultar(string $idNoBanco)`)
  e o registro `BancosPix::doCodigo()`. A Cora continua como está.
- Não mexer no que é da f6: `ArmazenamentoComCota`, `SituacaoDoEspaco`, `Plano`, `EspacoDoEscritorio`, `ContribuaController`,
  `contribua/index.html.twig`, `public/js/contribua.js`, `public/css/contribua.css`. A regra `^/api/webhooks/` do `security.yaml` já existe; não mexer.

## Decisões

| # | Decisão | Origem |
|---|---|---|
| D1 | Um adaptador por banco: `BancoInter` (código `inter`), `BancoDoBrasil` (`bb`) e `BancoPixPadrao` (`outro`, API Pix do BACEN), todos registrados em `BancosPix` | coordenador |
| D2 | Inter, BB e Outro seguem a API Pix do BACEN: OAuth2 `client_credentials` com `client_id` + `client_secret`, mTLS em toda chamada, cobrança imediata `PUT /cob/{txid}`, consulta `GET /cob/{txid}`. Uma base comum (`ClientePixBacen`) e diferenças por banco (URLs, cabeçalho de app key do BB) | documentação pública dos bancos |
| D3 | **txid é do adaptador**: cada `BancoPix` gera o identificador que o banco aceita. Cora mantém o formato dela; os BACEN geram `[a-zA-Z0-9]{26,35}` determinístico a partir do `PedidoDeCobrancaPix::$codigo` + chave de idempotência (mesma tentativa, mesmo txid) | coordenador |
| D4 | **Campos extras** que o desenho omite (ele mostra só ambiente, Client ID e certificado, que bastam para a Cora): `client_secret` (Inter, BB, Outro), chave PIX do recebedor (Inter, BB, Outro), app key (BB) e URL base da API Pix + URL do token (Outro). Aparecem **só** quando o banco escolhido precisa, no estilo dos campos do desenho; segredos cifrados no `CofreDeCertificado` e nunca devolvidos à tela (só "definido"/"não definido") | coordenador: omissão = convenção |
| D5 | O banco escolhido no seletor passa a ser gravado (hoje `IntegracaoBancaria::$banco` é fixo em `cora`). Os quatro bancos do desenho ficam habilitados; "Parceria" continua como está no #88 | desenho |
| D6 | Webhook `/api/webhooks/{banco}/pix` (como o desenho): para os bancos BACEN, lê o corpo `{"pix":[{endToEndId, txid, valor, horario}]}`, registra só o que for cobrança nossa (txid conhecido) e reconsulta no banco (mesmo `ReconsultarCobrancaNoBanco` da Cora). Nunca confia no valor do corpo: confirma pela consulta | padrão BACEN + #88 |
| D7 | Certificado `.pfx` com senha (comum no BB, A1): campo de senha do certificado, só para conversão no servidor, nunca gravado | documentação BB |
| D8 | Conciliação: o comando `app:cora:conciliar` passa a valer para o banco ativo (nome novo `app:contribua:conciliar`, mantendo o antigo como apelido) | generalização |
| D9 | Testes com `MockHttpClient` a partir das documentações oficiais; nenhuma chamada real. Credenciais e certificados reais ficam com o dono (passo a passo abaixo) | coordenador |

## Modelo de dados (uma migration)

`contribua_integracao_bancaria`: acrescenta `client_secret_cifrado` TEXT NULL, `chave_pix` VARCHAR(77) NULL, `app_key_cifrada` TEXT NULL,
`url_base` VARCHAR(255) NULL, `url_token` VARCHAR(255) NULL, `conta_corrente` VARCHAR(20) NULL (Inter, opcional). Sem mudança em `contribua_apoio` (o `txid` VARCHAR(60) comporta 35).

## Para o dono

1. **Acréscimo necessário (não desvio):** os campos da D4 não estão no desenho, mas sem eles Inter, BB e "Outro" não funcionam.
2. **Credenciais de cada banco** (passo a passo, quando quiser ligar):
   - **Inter:** Internet Banking PJ → API → Nova integração (escopos de cobrança PIX e webhook) → baixar o certificado `.crt` e a chave `.key`
     e anotar Client ID e Client Secret; a chave PIX é a da conta PJ.
   - **Banco do Brasil:** Portal do Desenvolvedor BB → aplicação com a API Pix → Client ID, Client Secret e app key; certificado A1 da empresa
     (`.pfx` com senha) cadastrado no portal; chave PIX da conta.
   - **Outro (PSP com API Pix padrão):** URL base da API Pix e URL do token fornecidas pelo PSP, Client ID, Client Secret, certificado e chave PIX.
3. O webhook do BACEN costuma chegar com mTLS do banco para o nosso servidor; o nginx de produção pode precisar aceitar o certificado do PSP.
4. **Trocar de banco com cobranças abertas:** as credenciais guardadas são só do banco configurado, então as cobranças abertas do
   banco anterior ficam **sem confirmação automática** (aviso, reconsulta e conciliação ignoram; nada é mandado ao banco errado).
   Não existe hoje fluxo para o Master confirmar um apoio à mão: o escritório que pagou pode clicar em "Já contribuí" (fica como
   apoio informado, sem confirmação do banco). Para não perder confirmações, troque de banco quando não houver cobrança aberta
   recente (vencem em 7 dias) ou volte ao banco anterior por um tempo para a conciliação passar. Ao trocar de banco (ou as URLs do
   "Outro"), o Client Secret e a app key guardados são apagados e precisam ser informados de novo.
5. **Webhook sem limite por txid:** como na Cora, o endereço é público e não há limite de requisições; só aviso com txid de
   cobrança nossa do banco ativo gera registro e fila (até 50 itens por aviso), e o mesmo `endToEndId` não é processado duas vezes.
   Limite por origem fica para quando houver limitador no `framework.yaml`.
