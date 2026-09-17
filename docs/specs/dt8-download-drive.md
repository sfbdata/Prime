# DT-8 — o download do Drive não pode gravar resposta de erro como documento

**Frente:** `fix-dt8-download-drive` (base `origin/master` @ `c365fe72`) · **Migration:** não ·
**Autorizada pelo dono em 2026-09-16.** Origem: DT-8 e D24 da spec da E2
(`docs/specs/e2-abstracao-de-storage.md` §9, na branch `e2-abstracao-storage`).

## 1. O defeito (provado por execução antes desta frente)

`GoogleDriveClient::baixarArquivo` fazia `GET …?alt=media` com `sink` e não conferia o status. O
Guzzle do Google nasce com `http_errors => false`, então 204, 206, 401, 403, 404, 429 e 5xx
**voltavam sem exceção**, com o corpo do erro no destino. O `ReconciliadorDePasta` (Via B) gravava
esse corpo como `PastaDocumento`, com `drive_file_id`, e a idempotência por esse id impedia um novo
download para sempre. No master o `tamanho_bytes` vem do `size` da API: o banco não denuncia o
arquivo errado.

Três agravantes, também provados:

- `refreshToken()` recusado (`invalid_grant`) **não lança**: o `Google\Client` fica sem token e o
  `authorize()` devolve o cliente HTTP **sem credencial**. O GET sai sem `Authorization` e o 403
  resultante vira o arquivo.
- O `token_callback` padrão do Google regrava o token renovado **sem o `refresh_token`**. Na
  segunda expiração (≈2h de vida do cliente) o `authorize()` passa a mandar o token vencido, sem
  renovar.
- Mesmo com `http_errors => true`, o corpo já está no destino quando a exceção sai: não basta
  lançar, é preciso descartar o destino.

## 2. Comportamento exigido

| # | Regra |
|---|---|
| R1 | O download só é válido quando a **resposta HTTP final** (depois dos redirects) é **200** |
| R2 | 204, 206, 3xx não seguido, 401, 403, 404, 429 e 5xx falham com `DownloadDoDriveFalhouException` |
| R3 | O request força `http_errors => false`: a validação de status é **nossa**, não do Guzzle |
| R4 | Em **qualquer** falha (status inválido, transporte, redirects demais, credencial), o destino é **removido** antes de a exceção sair |
| R5 | A exceção traz o `fileId`, o status (quando houve resposta) e o motivo do Google (`errors[0].reason` e `message`) quando o corpo é o JSON de erro dele; corpo que não é esse JSON não entra na mensagem |
| R6 | A mensagem nunca carrega credencial: refresh token, client secret e o access token corrente são ocultados **antes** do corte; cabeçalhos e opções da requisição nunca entram. A exceção de origem **não é encadeada** (a do Guzzle carrega a requisição, com o `Authorization`): dela ficam o nome curto da classe e a mensagem limpa |
| R7 | O contrato é fechado: fora a recusa de token (R8), **toda** falha do download — conexão, corpo incompleto, redirects demais, falha de rede ou resposta ilegível na renovação, até `\Error` da biblioteca do Google (ex.: `TypeError` quando o token volta como JSON que não é objeto; a mensagem leva arquivo e linha, já que nada é encadeado) e erro de configuração do client (OAuth incompleto, credencial ausente) — vira `DownloadDoDriveFalhouException` sem status |
| R8 | Renovação sem `access_token` utilizável (recusa, 200 sem token, token vazio, token que o `Google\Client` já dá por vencido — sem `expires_in` ou dentro da margem de 30 s) lança `TokenDoDriveRecusadoException` **antes** de qualquer chamada à API. No modo OAuth o download **não depende da renovação do middleware do Google**: com o token vencido, o próprio client renova antes do GET, com a mesma checagem — nenhum GET de download sai sem `Authorization`. A recusa fica guardada na instância (uma rodada de um escritório, ou uma mensagem): as chamadas seguintes falham sem novo POST; a próxima instância tenta de novo. Por escolha, isso vale também para erro passageiro que o Google devolve como JSON (`{"error": …}` num 5xx); falha de rede e resposta ilegível não ficam guardadas. A dica da mensagem depende do código: `invalid_grant` → reconectar o Drive; `invalid_client` → conferir client_id/secret; outros → nenhuma |
| R9 | As renovações feitas pelo middleware (listagens, envios, criação e renomeação de pasta) preservam o `refresh_token` já conhecido |
| R10 | **D16 intacta:** divergência entre o tamanho recebido e o `size` da API continua sendo aviso (na E2), nunca erro. Esta frente não mexe nisso |

O reconciliador **não muda**: ele já captura `\Throwable` no download, não cria linha, conta o erro,
apaga o temporário no `finally`, segue a rodada, e a próxima rodada tenta de novo. Os testes desta
frente passam a provar isso.

## 3. Costura de teste

`GoogleDriveClient::comClienteHttp(...)` (construtor estático) cria o mesmo client falando por um
`GuzzleHttp\ClientInterface` dado, aplicado ao `Google\Client` **antes** da renovação do token (que já
faz um POST). Fica fora do construtor de propósito: o serviço é autowired e um `ClientInterface`
registrado um dia seria injetado em silêncio. Em produção nada muda — o Google cria o cliente padrão
dele. A `GoogleDriveClientFactory` e o `services.yaml` não mudam.

## 4. Fora do escopo e riscos que ficam (registrados)

- **Redirect para outro host reenvia o Bearer:** o `google_auth` fica dentro do `allow_redirects` e
  recoloca o cabeçalho que o redirect removeu. Risco prático baixo (o Drive não redireciona o
  `alt=media` para fora); decisão do dono.
- **Modo service account:** a renovação continua com o middleware do Google (a R8 proativa é do modo
  OAuth). Nenhum chamador baixa arquivo nesse modo — a Via B usa a conexão OAuth do escritório.
- **Janela de microssegundos na R8:** se o token vencer exatamente entre a checagem do client e a do
  `authorize()`, a renovação vai para o middleware do Google. Uma recusa 4xx lança antes do GET (o
  cliente de token do middleware tem `http_errors` ligado); só um "200 sem token" nessa janela deixaria
  o GET sair sem `Authorization` — e a resposta não-200 é barrada pela R1, sem gravar nada.
- **Operações que não são download** (listar, enviar, criar/renomear pasta) seguem renovando pelo
  middleware; uma renovação que voltasse 200 sem token deixaria a chamada sair sem credencial e o
  Google a recusaria com exceção (o REST do Google lança em status ≥ 400). Nada é gravado por elas.
- Conferência por `md5Checksum`, timeout de leitura e novas tentativas com espera em 429/5xx.
- **Falha permanente é tentada de novo a cada rodada** (404 de arquivo apagado, 403 de arquivo
  bloqueado): conta erro em toda `--modo=importar`, sem marca. Custo baixo (a Via B é manual).
- **Download que falha numa subpasta deixa a seção criada e vazia** (o reconciliador cria a seção
  antes do download; anterior a esta frente). A seção é reaproveitada pelo nome na próxima tentativa;
  numa falha permanente fica vazia.
- **Registros já contaminados em produção:** a janela provável vai de **11/07** (commit `06cd7fe8`
  trouxe o `sink`; antes, o download era por `files->get`, que lança em status ≥ 400) a **14/08**
  (deploy do R2, que tirou a importação do cron e do worker). As datas de commit/deploy e a ausência
  de `--modo=importar|ambos` manual depois disso precisam ser confirmadas. A auditoria exige ler o
  disco (no master o `tamanho_bytes` veio do `size` da API) e não faz parte desta frente.
  🔴 **Limpar anulando o `drive_file_id` é proibido:** a Via A (`--modo=enviar`, que o cron roda)
  subiria o corpo de erro para o Drive do cliente como arquivo novo. Remover a linha e o arquivo, ou
  baixar de novo.
- **Uma recusa derruba a rodada do comando para os escritórios seguintes** (anterior a esta frente:
  antes saía um `Google\Service\Exception` do mesmo ponto, `listarSubpastas` fora de `try`). A
  memória da recusa não muda isso.
- **`descartar()` com link simbólico:** o `unlink` remove só o link; o conteúdo fica no alvo.
  Nenhum chamador passa link (o reconciliador usa `tempnam`). Destino sem permissão de escrita fica
  como está, em silêncio — e nada é gravado dele, porque o download lançou.
- **Integração com a E2** (`e2-abstracao-storage`, não tocada aqui). Quando ela trouxer o master:
  - `docs/frentes-ativas.md`: conflito textual na tabela (a E2 a reescreveu);
  - `FakeGoogleDriveClient`: as linhas de `destinosDeDownload` são idênticas nas duas frentes, e o
    modo de falha fica em outro trecho — sem conflito previsto (conferir no merge);
  - `EscritaInternaPorChaveArquiteturaTest` (E2) proíbe `file_put_contents` em `src/`: o descarte
    daqui esvazia com `ftruncate`, então a allowlist não precisa mudar;
  - ficam velhos na spec da E2: o DT-8/D24 do §9 ("bloqueio operacional até uma frente própria") e a
    nota de que o fake não sabe simular falha — atualizar na integração;
  - o `baixarArquivo` da E2 continua correto: o download lança antes de qualquer gravação por chave.

## 5. Provas

- Unitários do cliente com um dublê HTTP roteado (`DriveHttpFalso`) e sem rede. Os de status usam
  um cliente com `http_errors => true`, o que prova R3. Os de credencial e o ponta a ponta usam a
  configuração padrão do Google, a mesma da produção.
- Funcionais do comando com o `FakeGoogleDriveClient` falhando (rodada segue; a próxima tenta de
  novo) e ponta a ponta com o `GoogleDriveClient` real sobre o dublê HTTP.
- Provas por reintrodução de cada regra (§2) — ver o relatório da frente.
- Revisão por três revisores (HTTP e autenticação, banco × arquivo, testes), sem bloqueante; as
  correções dela estão nesta spec (R6–R8, §3, §4). `/review` depois das correções, sem bloqueante:
  token sem validade e `\Error` da biblioteca entraram em R7/R8. Re-revisão dessas correções, sem
  bloqueante; os menores dela viraram texto da mensagem, arquivo e linha do `\Error`, testes de falha
  passageira e a janela registrada acima.
