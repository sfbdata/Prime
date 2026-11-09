# BlueJus Voto: protocolo e lógica do protótipo (leitura do código)

Fonte: `docs/design/redesign-08-11-26/` (bj-voto-nucleo.js, bj-voto-testes.js, bj-voto-entrada.js, bj-voto-boletim.js, bj-voto-cenarios.js), com consultas pontuais ao `18 - Voto - VS 08.11.26.dc.html` só onde o núcleo não traz a regra (código de conferência, boletim de urna, comprovante, política de 3 tentativas da foto). Comparado com `ESPEC_VOTO.md` (seções 3, 4, 13, 15, 16, 16-A).

Conferência feita: `node` 22 rodou `bj-voto-testes.js`: **97 testes, 0 falhas, 197 ms** (PBKDF2 reduzido a 1.000 iterações, como no próprio teste).

---

## 0. Convenções do código

| Item | Definição exata |
|---|---|
| `hex(u)` | bytes para hex minúsculo |
| `sha(s)` | SHA-256 (WebCrypto) do texto em UTF-8; devolve hex de 64 caracteres. **Todos os hashes do protocolo são hash de texto** (campos hex unidos por `|`), não de bytes binários |
| `aleat(n)` | `crypto.getRandomValues` de n bytes |
| `bigAleat(lim)` | (bytes de lim + 8) bytes aleatórios, convertidos em BigInt, `% lim` (viés desprezível) |
| `modPow`, `modInv` | exponenciação e inverso modular em BigInt, escritos à mão (não são de tempo constante) |
| `norm(t)` | maiúsculas, remove tudo que não é `0-9A-ZÀ-Ü` |
| `grupoN(t)` | `norm(t)` ou `'GERAL'` |
| `PESO1` | 1.000.000 (peso em ponto fixo com 6 casas; peso 1 = 1.000.000) |
| `TAM_CEDULA` | 512 bytes |
| `Q` | ordem da curva P-256: `0xFFFFFFFF00000000FFFFFFFFFFFFFFFFBCE6FAADA7179E84F3B9CAC2FC632551` |
| Relógio | `Sistema.relogio()` (injetável nos testes). Atenção: Auditoria, `pedirToken`, `validarToken`, `_sessao` e `autenticar` usam `Date.now()`/`new Date()` direto |
| Armazenamento | `LojaLocal` (localStorage, JSON) ou `LojaMem` (Map). Operações: `obter`, `gravar`, `anexar` (push em lista), `mapa` (grava chave num objeto; **LojaLocal reordena as chaves em ordem alfabética** para não preservar a ordem de inserção; LojaMem não reordena) |

---

## 1. Modelo de dados

### 1.1 Chaves de armazenamento (`K`)

| Chave | Conteúdo | Domínio lógico |
|---|---|---|
| `bj-votacao-eleicoes` | lista de Eleições | Gestão |
| `bj-votacao-identidade` | lista de Registros de identidade (eleitores) | Identidade |
| `bj-votacao-perfis` | lista de Perfis (equipe) | Gestão / RBAC |
| `bj-votacao-trilha` | lista encadeada de eventos administrativos | Auditoria |
| `bj-votacao-incidentes` | lista simples de incidentes (sem cadeia) | Auditoria |
| `bj-votacao-tokens` | mapa `idH` para Código de acesso pendente | Identidade (acesso por código) |
| `bj-votacao-aptos:<eid>` | mapa `idH` para Linha da lista de aptos | Elegibilidade |
| `bj-votacao-ocorrencias:<eid>` | mapa `idH` para Ocorrência/avaliação | Elegibilidade |
| `bj-votacao-autoriz-chave:<eid>:<peso>` | chave privada RSA completa (n, e, d, p, q, dp, dq, qi em hex); vira `null` no encerramento | Autorizador |
| `bj-votacao-participacao:<eid>` | mapa pseudônimo para faixa horária (`YYYY-MM-DDTHH`, UTC) | Autorizador |
| `bj-votacao-pendente:<eid>:<pseudônimo>` | `{tk, ass}` ou `{tk, ass, usado:true}` | **Aparelho do eleitor** (no protótipo, mesmo armazenamento) |
| `bj-votacao-urna:<eid>` | lista encadeada de Entradas da urna | Urna |
| `bj-votacao-guardioes:<eid>` | as **5** partes de Shamir `{x, y}` juntas | Guardiões |
| `bj-votacao-partes-apresentadas:<eid>` | lista de `x` (1..5) dos guardiões que "apresentaram" | Guardiões |
| `bj-votacao-compromissos:<eid>` | declarada em `K.cri`, **nunca usada** | (sem uso) |
| `bj-votacao-resultado:<eid>` | `{resultado, chave}` (chave = escalar d em hex) | Apuração |
| `bj-votacao-comprovantes:<eid>` | mapa pseudônimo para Comprovante BJC | Autorizador (deveria) |
| `bj-votacao-nominal:<eid>` | mapa `idH` para Voto nominal (só sigilo aberto) | Apuração / voto aberto |

### 1.2 Eleição

Campos de configuração (gravados por `salvar`):

| Campo | Valores | No hash (`cfgPublica`)? |
|---|---|---|
| `id` | 8 bytes aleatórios hex (16 caracteres), se não vier | sim |
| `nome` | texto | sim |
| `modalidade` | `chapa`, `pauta`, `individual`, `consulta` | sim |
| `finalidade` | texto | sim |
| `inicio`, `fim` | ISO 8601 | sim |
| `grupos` | lista de textos (filtro por grupo/seção) | sim |
| `entidade` | texto | sim |
| `eventoNome` | texto; votações com o mesmo evento compartilham link e equipe (`escDe`) | sim (como `evento`) |
| `cenario` | id do cenário | sim |
| `ponderacao` | `um` ou `peso` | sim |
| `acesso` | `pin` ou `token` (código por e-mail) | sim |
| `auto` | abertura/encerramento automáticos | sim |
| `listaVotantes` | permite relação nominal de votantes | sim |
| `modoFim` | `horario` ou `mesa` | sim |
| `alterarVoto` | permite trocar o voto em sessão permanente | sim |
| `sigilo` | `secreto` (padrão), `aberto` (nominal após encerrar), `aberto_vivo` (nominal em tempo real) | só se diferente de `secreto` |
| `cargos[]` | `{nome, dig, regra, base, quorum, quorum2, cands[]}` | sim |
| `cargos[].regra` | `mais`, `absoluta`, `dois` (2/3), `unanime` | sim |
| `cargos[].base` | `validos`, `presentes`, `aptos` | sim |
| `cargos[].quorum`, `quorum2` | % de presença (1ª e 2ª convocação) | sim |
| `cargos[].cands[]` | `{num, nome, comp, desc, prop, foto}` | só `num`, `nome`, `comp` |
| `limNome` | limite de caracteres do nome da candidatura (30 no cenário OAB) | **não** |
| `identTipo` | `cpf`, `numero`, `email` | **não** |
| `consultaModo` | `ident` (padrão) ou `nome` | **não** |
| `limSessao` | `{prox: dias, total: dias}` (60/90 no condomínio) | **não** |
| `geo` | `nao`, `solicitar`, `obrigatoria` (só na tela) | **não** |
| `ordem` | `fixa` (só na tela) | **não** |

Campos gravados pelo sistema:

| Campo | Quando | Conteúdo |
|---|---|---|
| `status` | sempre | ver estados abaixo |
| `cfgHash` | `publicar` | `sha(JSON.stringify(cfgPublica(c)))` |
| `aptos` | `importarAptos` | relatório: `total, aptos, inaptos, invalidas, duplicadas, grupo30 (linhas com categoria), pesoAptos, classes, raras, semEmail, hash, em, ajustes` |
| `rsaP` | `abrir` | `{ <peso>: {n, e} }` chaves públicas RSA por classe de peso |
| `ecPub` | `abrir` | chave pública ECDH P-256 bruta (65 bytes, hex) |
| `compD` | `abrir` | `sha('D|' + dHex)` compromisso da chave privada da eleição |
| `n`, `k` | `abrir` | 5 e 3 (padrão) |
| `genesis` | `abrir` | `sha('BJV-GENESIS|' + eid + '|' + cfgHash + '|' + JSON.stringify(rsaP) + '|' + ecPub)` |
| `zeresima` | `abrir` | `{em, cedulas: 0, cabeca: genesis, participacao: 0}` |
| `convocacao` | `mesaAbrir` | 1 ou 2 |
| `limite` | `mesaAbrir`, `mesaPrazo` | epoch ms ou null |
| `sessao` | `suspender`/`retomar` | `{suspensa, proxima, entre, hist: [{tipo, em, proxima}]}` |
| `encerramento` | `encerrar` | `{em, cedulas (tokens distintos), cabeca, participacao (autorizações emitidas), motivo}` |

**Estados e transições**

| De | Para | Função | Guardas |
|---|---|---|---|
| (novo) | `rascunho` | `salvar` | papel comissão; `salvar` sempre força `rascunho` e preserva `aptos` |
| `rascunho` | `rascunho` | `salvar` | só rascunho pode ser alterado |
| `rascunho` | `publicada` | `publicar` | `validaCfg` sem problemas; calcula `cfgHash` |
| `publicada` | `aberta` | `abrir` (manual), `tick` (se `auto` e `inicio <= agora <= fim`), `mesaAbrir` (modo mesa, ignora `inicio`) | modo horário exige `agora >= inicio`; acesso por código exige lista importada; peso exige lista; até 64 pesos distintos; com RBAC e equipe própria, ao menos k guardiões |
| `aberta` | `aberta` (suspensa) | `suspender` | data da próxima sessão > agora; respeita `limSessao` |
| `aberta` (suspensa) | `aberta` | `retomar` (manual ou `tick` em `sessao.proxima`) | |
| `aberta` | `encerrada` | `encerrar` (manual), `tick` (auto, sem sessão, `agora > fim`), `_tickMesa` (prazo da mesa ou prazo total da sessão permanente) | antes de `fim` exige motivo; apaga chaves privadas RSA |
| `encerrada` | `apurada` | `apurar` | k partes de guardiões distintos; `sha('D|'+d) == compD`; urna íntegra e cabeça igual à do encerramento |

Não há estado "cancelada" nem "suspensa" como status: a suspensão é o objeto `sessao` dentro de `aberta`.

`aberta(e)` (pode votar agora?): status `aberta`; se `sessao.suspensa` e não `entre` então fechada; se `modoFim == 'mesa'` ou há `sessao` então aberta até `limite` (sem limite: aberta indefinidamente); senão `inicio <= agora <= fim`.

`validaCfg`: nome; `fim > inicio`; ao menos 1 cargo; cada cargo com candidaturas; nome até `limNome`; número só dígitos com exatamente `dig` dígitos; número sem repetição no cargo; candidatura com nome.

### 1.3 Registro de identidade (eleitor)

Campos: `uid` (10 bytes hex), `nome`, `ident`, `grupo` (normalizado), `grupoT` (texto original), `cpfFim` (2 últimos dígitos), `idH = sha('ID|'+grupoN(grupo)+'|'+norm(ident))`, `cpfH = sha('CPF|'+dígitos)`, `sal` (16 bytes hex), `pinH` (PBKDF2), `fotos` (`{cf, cv, doc, sel}` data URLs), `qualidade`, `consent` (ISO), `status`, `apto`, `erros`, `teste`, `revisado`, `posterior`, `decisao {em, por, motivo}`, `acesso` (`token` quando entrou por código), `sessH`, `sessExp`, `declaracao {em, texto: sha(texto da declaração)}`, `geos[]` (últimas 10: `{em, st, lat, lon, ac, canal: presencial|virtual}`).

Estados: `analise` (cadastro com PIN) para `habilitado` ou `recusado` (`decidir`, papel validador; `apto` = ok && apto). `analise` para `habilitado` com `posterior: true, revisado: false` (`habilitarPorLista`, se consta apto na lista). Entrada por código: nasce `habilitado`, `apto: true`, `revisado: false` (revisão posterior pelo validador). Não existe função de desbloqueio do PIN.

Duplicidade (`cadastrar`): recusa se outro registro tem o mesmo `idH`, ou o mesmo `cpfH` quando `multi` é falso (cenários condomínio e sociedade permitem várias unidades por CPF). O CPF **não** tem dígito verificador conferido no cadastro com PIN (só no pedido de código).

### 1.4 Perfil (equipe)

`{id (6 bytes hex), nome, email (minúsculo), papel, cargo (até 60), esc?, x? (1..5 para guardião)}`. Papéis: `admin, comissao, validador, guardiao, auditor, fiscal, conselho, master`. `esc` = escopo: `'ev-' + norm(eventoNome).toLowerCase()` ou `id` da eleição; sem `esc` = equipe padrão. Uma pessoa (e-mail) só tem um papel por escopo. Máximo 5 guardiões por escopo (`x` = menor livre).

### 1.5 Lista de aptos (por eleição)

Mapa `h -> {s: apto|inapto, g: categoria, p: peso, gr: grupo texto, id: ident em claro, nome, nomeN, emH: sha('EM|'+email), dec?: 1}`, onde `h = sha('ID|'+sec+'|'+norm(ident))` e `sec = 'GERAL'` se acesso por código, senão `grupoN(grupo)`. Situação apta se casa `/^(apto|apta|adimplente|regular|quite)$/i`. Peso: `round(parseFloat(texto com vírgula) * 1e6)`, vazio = 1.000.000. Hash da lista: `sha(JSON.stringify(chaves ordenadas.map(k => [k, s, g, p])))`. Importação permitida em `rascunho` e `publicada`; reimportar substitui a lista inteira.

### 1.6 Ocorrência e avaliação

`O[h] = {t (até 80), em, por, av}`. Se a linha é inapta, `av = {st: 'pendente', em}`; se apta, `av = null`. O conselho decide: `manter` ou `alterar` (justificativa até 160). `alterar` só em `rascunho`, `publicada` ou `aberta`; marca `s = 'apto'`, `dec = 1`, soma em `aptos.aptos`, `pesoAptos`, `ajustes`. Estados: `pendente` para `mantida` ou `alterada`.

### 1.7 Código de acesso pendente (`bj-votacao-tokens`)

`T[h] = {ch: sha(sal + cod), sal (12 bytes hex), exp, tent, ok, pedidos, ini}`. Ver seção 7.

### 1.8 Entrada da urna

`{seq, ant, token (32 bytes hex), p (peso), ass (assinatura RSA hex), c: {epk (65 bytes hex), iv (12 bytes hex), ct (528 bytes hex)}, h}` com `h = sha([seq, ant, token, p, ass, c.epk, c.iv, c.ct].join('|'))`. Sem horário, sem identidade, sem pseudônimo.

### 1.9 Comprovante de participação (BJC)

`cpa[pseudônimo] = {cod: 'BJC-XXXX-XXXX-XXXX' (6 bytes aleatórios), f: faixa 'YYYY-MM-DDTHH', g: grupo, p: peso}`. Gravado só uma vez por pseudônimo, depois do depósito.

### 1.10 Voto nominal (sigilo aberto)

`nom[idH] = {nome, id, gr, v: votos, p, em (ISO com segundos)}`; na troca de voto é sobrescrito.

### 1.11 Resultado

`{resultado: {cargos[{nome, itens[{num, nome, votos, peso}], brancos, nulos, pBrancos, pNulos}], cedulas, invalidas, pesoAptos, decisoes[], cabeca, em, participacao}, chave: dHex}`.

### 1.12 Trilha e incidentes

Trilha: `{i, em, ator, acao, det, ant, h}`, `h = sha([i, em, ator, acao, det, ant].join('|'))`, primeiro `ant = 'GENESIS'`. Incidentes: `{em, tipo, ref}` sem encadeamento. Tipos de incidente no código: Cadastro duplicado; PIN incorreto; Segunda autorização recusada; Depósito fora do período; Assinatura inválida na urna; Reuso de autorização; Cédula fora do padrão; Eleitor não habilitado tentou votar; Parte de guardião inválida; Urna divergente na apuração; Ação sem permissão; Perfil de outra votação; Código negado: dados não conferem; Excesso de pedidos de código; Código de acesso incorreto; Programação falhou: <mensagem>.

---

## 2. Funções públicas

### 2.1 Exportadas pelo módulo (`BJVotoNucleo`)

`cpfValido`, `PESO1`, `norm`, `grupoN`, `avaliar`, `Sistema`, `LojaMem`, `LojaLocal`, `K`, `verificarPacote`, `cfgPublica`, `sha`, `TAM_CEDULA`, `dividir`, `juntar`, `Q`, `formataComprovante(h) = 'BJV-' + h[0..16] maiúsculo em blocos de 4`.

Internas relevantes: `fdh`, `msgToken`, `cifrar`, `decifrar`, `derivaChave`, `importaPriv`, `hashEntrada`, `totaliza`, `finais`, `verUrna`.

### 2.2 `Auditoria`

| Função | O que faz |
|---|---|
| `registrar(ator, acao, det)` | anexa evento encadeado (fila de promessas para serializar) |
| `incidente(tipo, ref)` | anexa incidente (não encadeado) |
| `lista()`, `incidentes()` | leitura |
| `verificar()` | refaz a cadeia; `{ok, em?, total, cabeca}` |

### 2.3 `Identidade`

| Função | O que faz |
|---|---|
| `lista()`, `grava(L)`, `porId(uid)` | leitura/escrita |
| `localizar(sec, ident)` | acha por `idH` |
| `pin(p, sal)` | PBKDF2-SHA256, `it` iterações (120.000 padrão), 256 bits |
| `cadastrar(d)` | valida consentimento, nome/CPF/ident, PIN de 6+ dígitos, duplicidade; cria registro `analise` |
| `decidir(uid, ok, apto, ator, motivo)` | habilita ou recusa; marca `revisado` |
| `autenticar(uid, pin)` | PIN (bloqueio em 5 erros) ou, se `acesso == token`, confere sessão |
| `elegivel(uid, cfg)` | versão antiga; **não é usada** pelo `votar` |
| `pseudonimo(uid, eid)` | `sha('PART|' + eid + '|' + idH)` |
| `registrarLocal(uid, g)` | guarda geolocalização (últimas 10) e registra na trilha |

### 2.4 `Autorizador`

| Função | O que faz |
|---|---|
| `gerar(eid, peso)` | gera RSA-PSS 2048 (e = 65537) via WebCrypto só para obter n, d, p, q; grava a chave privada; devolve `{n, e}` |
| `conjunto`, `participou`, `total` | conjunto de pseudônimos que já receberam autorização (cache em memória) |
| `emitir(eid, ps, cegoHex, faixa, peso)` | recusa pseudônimo repetido; grava `participacao[ps] = faixa`; assina o valor cego com CRT |
| `apagarChave(eid, pesos)` | grava `null` nas chaves privadas |

### 2.5 `Urna`

| Função | O que faz |
|---|---|
| `lista(eid)`, `gastos(eid)` | entradas; conjunto de tokens já usados |
| `depositar(eid, cfgAberta, rsaP, genesis, ced, troca)` | serializado em fila; confere período, classe de peso, assinatura, token não gasto (salvo `troca`), tamanho `ct == 528`; anexa com hash encadeado; devolve `h` |
| `procurar(eid, cod)` | aceita só hex (mínimo 16), acha entrada cujo `h` começa pelo código; devolve `{seq, h}` |

### 2.6 `Sistema` (orquestra; no real, vira vários serviços)

| Grupo | Funções |
|---|---|
| Equipe/RBAC | `perfis`, `equipe(esc)`, `escDe(c)`, `_ex`, `_exE`, `designar`, `removerPerfil`, `atualizarPerfil`, `_rascEq`, `removerEquipe`, `aplicarEquipe` |
| Identidade | `decidir` (validador), `pedirToken`, `validarToken`, `concluirToken`, `_sessao` |
| Elegibilidade | `aptos`, `importarAptos`, `visivel`, `elegivel`, `habilitarPorLista`, `ocorrencias`, `registrarOcorrencia`, `decidirAvaliacao`, `consultarSituacao`, `baseAptos` |
| Eleição | `eleicoes`, `eleicao`, `gravaEle`, `salvar`, `validaCfg`, `publicar`, `definirFoto`, `abrir`, `aberta`, `tick`, `_tickMesa`, `mesaAbrir`, `mesaPrazo`, `suspender`, `retomar`, `encerrar` |
| Voto | `votar`, `jaVotou`, `comprovante`, `validarComprovante` |
| Guardiões | `partesGuardioes`, `partesApresentadas`, `apresentarParte` |
| Apuração/Auditoria | `apurar`, `resultado`, `verificar`, `pacote`, `participacaoGrupos`, `participantes`, `relacaoVotantes`, `votosNominais`, `conferirNominal` |

Detalhes de cada uma estão no protocolo (seção 3) e nas seções 6 a 11.

---

## 3. Protocolo passo a passo

### 3.1 Publicação e código de conferência

1. `publicar`: `validaCfg`; `cfgHash = sha(JSON.stringify(cfgPublica(c)))`. A ordem das chaves é a do literal de `cfgPublica`; números saem como `JSON.stringify` do JS. Reproduzir no PHP exige a mesma serialização (sem escapar `/` e Unicode, mesma ordem, mesmo formato de número). Recomendação: canonicalização definida (RFC 8785) ou concatenação de campos.
2. Código de conferência (tela, não núcleo): `cfgHash[0..4] + '-' + cfgHash[4..8]` em maiúsculas (32 bits). Evento com várias votações: concatena os 4 primeiros hex de cada `cfgHash`, pega 8, formata `XXXX-XXXX`. Link: `https://www.bluejus.com.br/voto/e/<id>` (evento: `/voto/v/<eventoId>`). O boletim de urna mostra "Código identificação" = `cfgHash[0..8]` maiúsculo.
3. Foto da candidatura (`definirFoto`) pode mudar depois de publicar (até abrir) porque não entra no hash.

### 3.2 Abertura (`abrir`)

1. Guardas da tabela 1.2.
2. Classes de peso: `ponderacao == 'peso'`: todos os pesos distintos da lista (inclusive inaptos), máximo 64; senão `[1.000.000]`.
3. Para cada peso (em ordem crescente): `Autorizador.gerar` cria **RSA 2048, e = 65537** (WebCrypto `RSA-PSS` só como gerador; a assinatura não é PSS). Pública em `rsaP[peso] = {n, e}`.
4. Chave da eleição: **ECDH P-256** (WebCrypto). `ecPub` = ponto não comprimido (65 bytes). `d` = escalar privado (32 bytes, do JWK).
5. **Shamir 3 de 5** sobre `Q` (ordem da P-256), implementação própria em BigInt: polinômio de grau 2 com `co[0] = d`, `co[1], co[2] = bigAleat(Q)`; partes `x = 1..5`, `y` em hex de 32 bytes. As 5 partes vão juntas para `bj-votacao-guardioes:<eid>`. A chave privada não é gravada.
6. `compD = sha('D|' + dHex)`.
7. `genesis = sha('BJV-GENESIS|' + eid + '|' + cfgHash + '|' + JSON.stringify(rsaP) + '|' + ecPub)`.
8. `status = 'aberta'`, `zeresima = {em, cedulas: 0, cabeca: genesis, participacao: 0}`; trilha "Votação aberta".

### 3.3 Voto (`votar(e, uid, pin, votos)`)

1. Eleição existe e `aberta(e)`.
2. `Identidade.autenticar(uid, pin)` (PIN ou sessão do código).
3. `Sistema.elegivel(uid, c)`: `habilitado`; filtro de grupo (exceto acesso por código); se há lista, precisa constar como `apto`; sem lista, vale `r.apto`. Falha gera incidente.
4. `votos.length == cargos.length` (cada item é o número digitado, `'BRANCO'` ou outro texto que vira nulo).
5. Pseudônimo `ps = sha('PART|' + eid + '|' + idH)`. Peso: da lista se `ponderacao == 'peso'`, senão 1.000.000.
6. Lê pendente `bj-votacao-pendente:<eid>:<ps>`. Se `usado` e sem sessão permanente: recusa ("já votou").
7. Se não há pendente (primeira vez), **no aparelho**:
   - `tk = hex(32 bytes aleatórios)`.
   - Mensagem: `'BJV-TOKEN|' + eid + '|' + peso + '|' + tk`.
   - **FDH próprio**: concatena `sha(i + '|' + msg)` para i = 0, 1, 2... até ter o tamanho do módulo (256 bytes = 8 blocos), converte em BigInt e faz `% n`. Não é o EMSA-PSS da RFC 9474 nem MGF1.
   - Cegamento: `r = bigAleat(n)` com `r >= 2` (sem conferir `mdc(r, n) = 1`); `cego = m * r^e mod n`.
   - Faixa: `new Date(relogio).toISOString().slice(0, 13)` (hora cheia em UTC).
8. **Autorizador** `emitir`: se `ps` já está no conjunto, recusa e registra incidente; senão grava `participacao[ps] = faixa` e assina `cego^d mod n` por CRT (`m1 = x^dp mod p`, `m2 = x^dq mod q`, `h = qi*(m1-m2) mod p`, `s = m2 + h*q`). Sem conferência pós-assinatura (proteção contra falha), sem tempo constante.
9. Aparelho descega: `ass = sb * r^-1 mod n`. Grava `pendente = {tk, ass}` **antes** do depósito (permite repetir o depósito se falhar, sem pedir nova autorização). Não verifica a assinatura antes de depositar.
10. **Cifra da cédula** (`cifrar`):
    - Texto: `JSON.stringify({v: votos, r: hex(8 bytes aleatórios)})` em UTF-8; erro se passar de 512 bytes; preenche com espaços (0x20) até **512 bytes exatos**.
    - Par efêmero ECDH P-256; `epk` = pública bruta (65 bytes).
    - Segredo: `deriveBits(ECDH(efêmera, ecPub), 256)` (coordenada x, 32 bytes).
    - **HKDF-SHA-256**: `salt = epk (65 bytes)`, `info = 'BJV-CEDULA'`, saída AES-256.
    - **AES-256-GCM**: IV 12 bytes aleatórios, `additionalData = eid` (UTF-8), tag de 16 bytes anexada. `ct` = 528 bytes.
    - Formato: `{epk: hex 130, iv: hex 24, ct: hex 1056}`. É um ECIES montado à mão sobre primitivas WebCrypto (equivalente funcional a HPKE base mode, mas não é HPKE).
11. **Urna** `depositar` (serializado): `aberta`? classe `rsaP[p]` existe? `ass^e mod n == fdh(msgToken(eid, token, p), n)`? token não gasto (ou `troca`)? `ct` com 528 bytes? Monta entrada `{seq, ant, token, p, ass, c}`, `h = sha(campos unidos por |)`, anexa. Devolve `h`.
12. Pós-depósito: pendente vira `null` (ou `{tk, ass, usado: true}` se `alterarVoto`). Se sigilo aberto, grava voto nominal com hora em segundos. Gera BJC (se ainda não existe) com faixa, grupo e peso.
13. Tela: mostra `formataComprovante(h)` (BJV) e oferece o comprovante BJC com a hora exata do aparelho no momento (não gravada).

### 3.4 Troca de voto (sessão permanente)

Só quando `alterarVoto` e existe `c.sessao`. O aparelho reaproveita `{tk, ass}` guardados; a urna aceita o mesmo token com `troca = true` e anexa nova entrada. Na apuração e no verificador, `finais()` mantém só a última entrada de cada token. O Autorizador não é chamado de novo.

### 3.5 Encerramento (`encerrar`)

Antes de `fim` exige motivo. Espera a fila da urna, roda `verificar` (resultado não bloqueia), **apaga as chaves privadas RSA**, grava `encerramento = {em, cedulas: tokens distintos, cabeca, participacao: total de autorizações, motivo}`.

### 3.6 Guardiões e apuração (`apresentarParte`, `apurar`)

1. `apresentarParte` (papel guardião, eleição `encerrada`): só registra o número `x` do guardião em `partes-apresentadas`. O guardião não entrega nada: a parte já está no servidor.
2. `apurar(e, ator, partes)` (papel comissão): eleição `encerrada`; ao menos k valores de `x` distintos em `partes`; `d = juntar(partes.slice(0, k))` (Lagrange em 0, mod Q); `sha('D|'+d) == compD`, senão incidente; `verificar(e)` ok e cabeça igual à do encerramento, senão incidente e bloqueio.
3. `finais(urna)`, embaralha (Fisher-Yates com `bigAleat`, só em memória), importa `d` como JWK e decifra cada cédula (falha vira `ce = null`, contada como inválida).
4. `totaliza`: cédula inválida se não decifra ou `v.length != cargos`; `'BRANCO'` soma branco; número existente soma votos e peso; qualquer outro valor é nulo. Ordena por peso decrescente e depois número.
5. `avaliar` (seção 10) gera `decisoes`.
6. Grava `{resultado, chave: d}`; status `apurada`.

### 3.7 Pacote público e verificador

`pacote(e) = {formato: 'BJV-2', cfg: cfgPublica, cfgHash, rsaP, ecPub, genesis, compD, zeresima, encerramento, urna: lista completa, chave: d ou null, resultado}`.

`verificarPacote(p)` refaz: `sha(JSON.stringify(cfg)) == cfgHash`; `genesis`; `verUrna` (sequência, encadeamento, hash de cada entrada, token repetido salvo `alterarVoto`, assinatura RSA de cada entrada com a chave da classe); cabeça igual à do encerramento; chave e resultado presentes; `sha('D|'+chave) == compD`; decifra os finais; `totaliza`; compara `JSON.stringify([cargos, cedulas, invalidas])` com o publicado.

Não confere: `decisoes` (quórum, vencedor), `pesoAptos`, `participacao` contra número de cédulas, hash da lista de aptos, `zeresima.cabeca == genesis`. Tudo é autorreferente: um pacote inteiramente fabricado passa, salvo se `cfgHash`, `genesis` e cabeça forem publicados por fora.

### 3.8 Peso por chave

Cada valor de peso tem um par RSA próprio. O peso entra na mensagem assinada (`BJV-TOKEN|eid|peso|tk`) e escolhe a chave. Trocar o peso da cédula invalida a assinatura (teste 48). Limite de 64 classes. `raras` = classes com menos de 3 aptos (alerta: o peso, que fica em claro na urna, pode identificar o eleitor).

### 3.9 Três códigos diferentes

| Código | Formato | Origem | Prova |
|---|---|---|---|
| BJV (depósito) | `BJV-XXXX-XXXX-XXXX-XXXX` | 16 primeiros hex do `h` da entrada da urna | que a cédula está na urna (`procurar` devolve a posição `seq`) |
| BJC (participação) | `BJC-XXXX-XXXX-XXXX` | 6 bytes aleatórios, sem ligação com a cédula | que a pessoa participou (`validarComprovante`); QR para `.../voto/e/<id>/c/<BJC>` |
| BJU (boletim de urna) | `BJU-XXXX-XXXX-XXXX` | 12 primeiros hex de `sha(payload do boletim)` | autenticidade do boletim |

`procurar` aceita só hex: quem passar o código com o prefixo `BJV-` não acha (o `B` é hex e sobra). A tela remove o prefixo antes; o servidor deve fazer o mesmo.

### 3.10 Faixas horárias e onde a hora exata aparece

- Autorizador: `participacao[ps] = 'YYYY-MM-DDTHH'` (UTC). No LojaLocal, gravado em ordem alfabética do pseudônimo.
- BJC: mesma faixa. 2ª via mostra "das HHh às HH+1h" (horário local do navegador).
- Urna: sem horário; só a ordem `seq`.
- Hora exata existe em: comprovante emitido na hora (relógio do aparelho, não gravado); voto nominal (sigilo aberto); `geos[].em` (identificação); trilha da auditoria em `concluirToken`/`pedirToken` ("Identificação por código..."), que ocorre segundos antes do voto.

### 3.11 Trilha de auditoria

Cadeia SHA-256 de texto; serializada por fila de promessas; verificação detecta qualquer campo alterado (teste 28). Incidentes fora da cadeia (podem ser apagados sem detecção).

---

## 4. O que roda em cada domínio (protótipo)

| Domínio | Código | Armazenamento | Desvios do ESPEC §3 |
|---|---|---|---|
| Identidade | classe `Identidade`; `Sistema.pedirToken/validarToken/concluirToken/_sessao/decidir/habilitarPorLista` | `identidade`, `tokens` | `habilitarPorLista` cruza com Elegibilidade direto |
| Elegibilidade | métodos do `Sistema` (`importarAptos`, `aptos`, `visivel`, `elegivel`, ocorrências, `consultarSituacao`) | `aptos:<e>`, `ocorrencias:<e>` | não é classe separada; a lista guarda ident e nome em claro |
| Autorizador | classe `Autorizador` | `autoriz-chave:<e>:<p>`, `participacao:<e>` | os comprovantes BJC (`comprovantes:<e>`) são gravados pelo `Sistema.votar`, não pelo Autorizador |
| Aparelho do eleitor | trecho de `Sistema.votar` (token, FDH, cegar, descegar, cifrar) | `pendente:<e>:<ps>` | o pendente liga pseudônimo, token e assinatura no mesmo armazenamento; com `alterarVoto` fica gravado até o fim |
| Urna | classe `Urna`, `verUrna` | `urna:<e>` | ok: sem identidade nem pseudônimo (teste 12) |
| Guardiões | `dividir`, `juntar`, `apresentarParte`, `partesGuardioes`, `partesApresentadas` | `guardioes:<e>` (as 5 partes juntas), `partes-apresentadas:<e>` | o guardião nunca recebe a parte; `apurar` aceita qualquer lista de partes (a comissão pode passar `partesGuardioes` direto) |
| Apuração | `Sistema.apurar`, `totaliza`, `avaliar`, `finais` | `resultado:<e>` | reconstrói a chave inteira num ponto e publica `d` |
| Auditoria | classe `Auditoria`, `verificarPacote` | `trilha`, `incidentes` | incidentes sem cadeia |

---

## 5. PIN

- Formato: só dígitos, ao menos 6 (`/^\d{4,}$/` e `length >= 6`).
- Hash: **PBKDF2-HMAC-SHA-256**, sal aleatório de 16 bytes, **120.000 iterações** (padrão; testes usam 1.000), saída de 256 bits, hex.
- Bloqueio: contador `erros`; com `erros >= 5` recusa antes de conferir ("Acesso bloqueado por tentativas incorretas. Procure a comissão."). Erro incrementa e registra incidente "PIN incorreto" (ref = grupo). Acerto zera. Sem desbloqueio por tempo e sem função de desbloqueio no núcleo nem na tela.
- Observação: PIN de 6 dígitos tem 10^6 combinações; se o banco vazar, o hash cai por força bruta em pouco tempo, qualquer que seja a contagem de iterações. A defesa real é o limite de tentativas no servidor.

## 6. Sessão do votante por código

`sess` = 24 bytes aleatórios (hex); guarda `sessH = sha(sess)` e `sessExp = agora + 1 h`. No `votar`, o parâmetro `pin` recebe `sess`. Sem limite de tentativas (192 bits).

## 7. Acesso por código (`pedirToken`, `validarToken`, `concluirToken`)

1. `pedirToken(d, eids)`: `tipo` = `cpf` (padrão), `numero` ou `email`; o valor vem em `d.cpf`. CPF com dígitos verificadores (`cpfValido`, recusa todos iguais). Nome com ao menos 2 palavras e e-mail com formato válido (no tipo `email`, o e-mail é o próprio identificador).
2. `h = sha('ID|GERAL|' + norm(ident))`. Precisa constar como `apto` em alguma das eleições, com `nomeN` igual (se a lista tem nome) e `emH` igual (se a lista tem e-mail). Senão incidente "Código negado" e "Os dados não conferem com a lista de votantes.".
3. Limite de pedidos: 5 por janela de 1 hora por identificação (contada a partir do primeiro pedido); excesso gera incidente.
4. Código: `uint32 aleatório % 1.000.000`, 6 dígitos com zeros (viés de módulo pequeno). Guarda `sha(sal12 + cod)`, validade **10 minutos**, `tent = 0`. Devolve `codDemo` (o protótipo mostra o código na tela) e e-mail mascarado `x***@dominio`.
5. `validarToken(ident, cod)`: expirado recusa; `tent >= 5` recusa ("Tentativas esgotadas. Peça um novo código."); errado incrementa `tent` e registra incidente. Certo: se já existe registro `acesso: token` com esse `idH`, apaga o pendente e abre sessão; se não, marca `ok = true` e estende a validade para **15 minutos** para concluir.
6. `concluirToken(d)`: exige `ok` e dentro do prazo; fotos `doc` e `sel`; `consentiu` e `declarou` (declaração sob o art. 299 do Código Penal, guardada como `sha(texto)`). Cria registro `habilitado`, `apto`, `acesso: 'token'`, `revisado: false`, grupo `GERAL`, sem PIN; abre sessão de 1 hora.
7. Revisão humana é posterior; o voto depositado não pode ser retirado.

## 8. Consulta de situação

`consultarSituacao(eids, nome, ident, grupo)`: limite global de 20 consultas por 10 minutos (em memória, não por IP); nome com 2+ palavras e igual exato (normalizado) ao da lista; sem identificação só se `consultaModo == 'nome'`; várias linhas sem identificação pedem identificação; várias com identificação pedem grupo (`ambGrupo`). Devolve situação, ocorrência, avaliação, justificativa e o cadastro (`uid`, `status`, `acesso`).

## 9. Análise de foto (`bj-voto-entrada.js`, só no navegador)

Luminância `Y = 0,299 R + 0,587 G + 0,114 B`. Amostra para análise: lado maior 800 px; para localizar o documento: 320 px.

Limites (`LIM`):

| Critério | Regra | Código | Duro? |
|---|---|---|---|
| Resolução | menor lado < 640 px (documento) ou < 480 px (selfie) | `resolucao` | sim (continua avaliando) |
| Escura | média < 40 ou percentil 98 < 110 | `escura` | sim (para) |
| Estourada | percentil 0,2 > 170 | `estourada` | sim (para) |
| Sem conteúdo | p98 menos p2 < 25 | `branco` | sim (para) |
| Foco selfie | nitidez na área central (margem de 20%) < 0,12 | `foco` | não |
| Foco documento | nitidez na área do documento < 0,20 | `foco` | não |
| Documento não achado | componente < 2,5% da imagem | `sem-doc` | não |
| Toca as 4 bordas | | `sem-margem` | não |
| Cortado | toca 1 a 3 bordas (lista os lados) | `cortado` | não |
| Distante | área do retângulo do documento < 15% | `pequeno` | não |
| Documento escuro | média dentro do documento < 60 | `escura` | não |
| Reflexo (só `cartao`) | maior mancha com Y >= 250 dentro do documento > 8% | `reflexo` | não |
| Reflexo extremo | mesma medida > 50% | `estourada` | sim |

Nitidez = percentil 99,5 do gradiente de Sobel (após média 3x3) dividido por (p98 menos p2).

Localização do documento: suaviza 3x3; Sobel/4; borda se gradiente > max(14; 0,1 x (p98 menos p2)); densidade de bordas >= 24% numa janela de raio max(3; 1,8% do lado); fechamento (dilata e erode) com raio max(4; 3,5% do lado); maior componente 4-conexo. Toque na borda: margem max(2; 0,8% do lado) e ao menos 22% da extensão do objeto. Reforço por tom (Otsu): se as médias das classes diferem 40+, decide o fundo pela maioria da moldura, pega o maior componente (>= 10% da imagem) com sobreposição >= 60% e acrescenta lados tocados (20% de cobertura e lado >= 8% da imagem).

Tipos: `cartao` (documento com foto), `papel` (folha), `selfie`. Saída `{ok, dura, motivos[{cod, tx, dura}], med{w, h, media, p95, nitidez, area, lados, reflexo}}`.

Política da tela: aprovado reduz para 640 px; reprovado por motivo não duro na 3ª tentativa entra "com ressalva para conferência humana"; motivo duro sempre recusa. Não confere autenticidade nem rosto.

## 10. Regras de decisão (`avaliar`)

Por cargo: `qx` = `quorum2` se 2ª convocação e definido, senão `quorum`. `val` = soma de peso dos votos válidos; `pres = val + pBrancos + pNulos`; `quorumPct = pres x 100 / aptosP` (`aptosP` = `pesoAptos` com peso, ou aptos x 1.000.000; sem lista, habilitados elegíveis x 1.000.000). `base` = aptos, presentes ou válidos. Primeiro e segundo por peso; empate se pesos iguais. Decidido se quórum ok, sem empate, primeiro com peso > 0 e: `mais` (maioria simples), `absoluta` (2a > base), `dois` (3a >= 2 x base), `unanime` (a >= base). Situação: "Quórum não atingido", "Empate", "Decidido", "Critério não atingido". Empate nunca é desfeito automaticamente.

## 11. Mesa, sessão permanente e agendador

- `mesaAbrir(e, ator, {convocacao, minutos})`: só `modoFim == 'mesa'`; abre mesmo antes de `inicio`; `limite = agora + minutos` (0 = sem prazo).
- `mesaPrazo(e, ator, min)`: redefine `limite` a partir de agora.
- `suspender(e, ator, {proxima, entre})`: próxima sessão no futuro; com `limSessao`: até `prox` dias (60) de agora e até `total` dias (90) da abertura. Zera `limite`. `entre = true` mantém a votação aberta entre sessões.
- `retomar`: tira a suspensão.
- `tick()` (a tela chama a cada 5 s e na carga): trava reentrância; `_tickMesa` (retoma na data; encerra se passou `total` dias da abertura; encerra se passou `limite`); para eleições `auto`: abre se `publicada` e dentro do período; encerra se `aberta`, sem sessão, e passou `fim`. Falha vira incidente uma vez por eleição.

## 12. Papéis e permissões (com `exigirPapeis`)

| Ação | Papel |
|---|---|
| designar, remover, atualizar perfil | admin (o primeiro admin é livre) |
| montar/remover equipe própria | admin; comissão só com a votação em rascunho, e só vagas "A designar" |
| decidir cadastro | validador |
| criar, publicar, abrir, encerrar, mesa, suspender, retomar, importar lista, foto, ocorrência, apurar | comissão |
| decidir avaliação de inaptidão | conselho |
| apresentar parte | guardião |
| relação de votantes | comissão, auditor, fiscal |
| lista nominal com fotos e localização (`participantes`) | comissão, auditor, master |

Escopo (`_exE`): perfil com `esc` só atua na votação/evento do seu escopo; perfil da equipe padrão é recusado se a votação tem equipe própria (exceto comissão padrão em rascunho); master passa pelo escopo. Admin não pode ser removido; guardião de votação aberta ou encerrada não pode ser removido.

## 13. Boletins e comprovantes (`bj-voto-boletim.js`)

Tudo é desenhado em canvas e embutido como JPEG num PDF 1.4 escrito à mão (sem texto selecionável, sem assinatura digital). Fundo de segurança: gradiente iridescente, ondas guilhochê, marca d'água diagonal "BLUEJUS · <código>", moldura dupla, bordas onduladas em 3 cores, fita vertical "BLUEJUS VOTO", microtexto com o código, QR (biblioteca `qrcode-generator`, correção M), "CÓDIGO DE VALIDAÇÃO" em vermelho e selo com anel "BLUEJUS AUTENTICO <código>". Aviso fixo: "Ambiente demonstrativo. Não é documento oficial de órgão eleitoral.".

- `gerar(d)` (relatórios e boletins A4): página 800 x 1131 em escala 3, PDF A4 (595,28 x 841,89 pt), JPEG 0,92. `d = {titulo, cod, qr, secoes[{h, texto[], linhas[[rótulo, valor]], tabelas[{titulo, cols, itens}], fichas[{nome, linhas, imgs[{src, r}]}]}], assin[2], arq, retorno}`. Rodapé "Página i de n"; duas linhas de assinatura.
- `comprovante(d)` (BJC): canvas 800 x 650; JPG (0,93) ou PDF A4 com linha pontilhada "Recorte na linha pontilhada". Campos: eleição, eleitor(a), identificação (mascarada no acesso por código), "DATA E HORA DA PARTICIPAÇÃO" (ou "DATA E FAIXA HORÁRIA (2ª VIA)"), "LOCALIZAÇÃO NO ACESSO", textos "Atesta somente a participação do(a) eleitor(a). Não revela o voto." e "Código distinto do comprovante de depósito da cédula.".
- `urna(d)` (boletim de urna, bobina de **80 mm**): largura 400 unidades em escala 3; PDF com largura **226,77 pt (80 mm)** e altura proporcional; fonte Courier New. Ordem: logotipo; "Boletim de Urna"; nome da eleição e data do encerramento; "Ambiente demonstrativo"; topo (grupo, "Local de votação: On-line", "Eleitores aptos", "Comparecimento", "Eleitores faltosos", "Código identificação" = `cfgHash[0..8]`, data e hora de abertura e fechamento); "RESUMO DO BOLETIM"; "Código Verificador"; por cargo: "Opção / Núm / Votos" e totais ("Eleitores aptos", "Total de votos nominais", "Brancos", "Nulos", "Total apurado"); QR de 250 px com o payload; "HASH SHA-256 DO QR CODE" em linhas de 32; selo; "Confira em bluejus.com.br"; código BJU.
- Payload do boletim (tela): `BJV-BU;e=<id>;apt=<aptos>;com=<participacao>;ced=<cedulas>;c<i>.<num>=<votos>;...;c<i>.br=<brancos>;c<i>.nu=<nulos>;cab=<32 primeiros hex da cabeça>`. `hash = sha(payload)`; `dig` = decimal dos 15 primeiros hex, 12 dígitos; resumo = `cedulas` com 3 dígitos + `dig[0..3]` em grupos de 3; verificador = `dig[3..12]` em grupos de 3; `BJU = hash[0..12]`. Mostra contagens, não pesos. "Comparecimento" = autorizações emitidas, que pode ser maior que cédulas.
- "Impressão digital do programa" (relatório): `sha` dos textos de nucleo, boletim e entrada unidos por `|`.

## 14. Cenários (`bj-voto-cenarios.js`)

Campos de cada cenário: `id`, `r` (rótulo), `modalidade`, `ponderacao`, `multi` (CPF com várias unidades), `lista` (pede lista), `modoFim`, `alterar`, `limSessao`, `acesso`, `rot {ent, id, gr, a1, a2, pauta}`, `docs` (4 fotos: `cf` frente, `cv` verso, `doc` comprovante, `sel` selfie; câmera traseira nas 3 primeiras e frontal na selfie), `cargo` (modelo `{nome, dig, regra, base, quorum, quorum2, cands}`), `ajuda`, `equipe` (10 vagas: comissão, validador, conselho, 5 guardiões, auditor, fiscal), `cargos` (sugestões), `identTipo`.

| id | Rótulo | Modalidade | Peso | Particularidades | Cargo modelo |
|---|---|---|---|---|---|
| condominio | Assembleia de condomínio | pauta | peso | multi, lista, mesa, alterar, limSessao 60/90; docs: titularidade ou procuração | 1 dígito, Sim/Não, maioria dos válidos, quórum 50, 2ª convocação 0 |
| classe | Entidade de classe ou conselho | chapa | um | identTipo `numero`; carteira profissional | 2 dígitos, maioria |
| associacao | Associação ou clube | individual | um | acesso `token`, lista; id = CPF | 2 dígitos |
| sindicato | Sindicato | chapa | um | matrícula sindical | 2 dígitos |
| cooperativa | Cooperativa | pauta | um | | 1 dígito, Sim/Não |
| sociedade | Sócios ou acionistas | pauta | peso | multi, lista | 1 dígito, Sim/Não, maioria absoluta sobre aptos |
| ensino | Instituição de ensino | individual | um | | 2 dígitos |
| consulta | Consulta interna | consulta | um | | 1 dígito, Sim/Não |
| livre | Personalizado | individual | um | | 2 dígitos |

Sugestões de cargos: condomínio (Síndico(a), Subsíndico(a), Conselho fiscal, Aprovação de contas, Previsão orçamentária, Obra ou benfeitoria, Alteração da convenção); classe (Diretoria, Conselho, Delegados, Caixa de assistência); associação (Presidência, Vice-presidência, Diretoria, Conselho fiscal, Conselho deliberativo); sindicato (Diretoria executiva, Conselho fiscal, Delegados sindicais); cooperativa (Conselho de administração, Conselho fiscal, Destinação das sobras, Prestação de contas); sociedade (Aprovação das contas, Destinação dos lucros, Eleição de administradores, Alteração do contrato social); ensino (Reitoria ou direção, Vice-direção, Coordenação de curso, Representação estudantil); consulta (Pergunta da consulta).

## 15. Leitura da lista (`lerLista`)

`.xls` recusado. `.xlsx` lido por leitor ZIP próprio (diretório central + `DecompressionStream('deflate-raw')`), `sharedStrings` e a primeira planilha por nome. CSV: tira BOM; separador `;` (se tem e é maioria), senão tab, senão vírgula; aspas com `""`. Texto UTF-8 estrito, senão Windows-1252. Cabeçalhos normalizados (sem acento, minúsculo, só alfanumérico): ident (identificacao, inscricao, numero, matricula, registro, carteira, cpf, unidade...), grupo (grupo, bloco, torre, regional, secao, seccional, subsecao), situacao (situacao, status, aptidao), peso (peso, fracao, fracaoideal, quotas, cotas), categoria, nome (nome, nomecompleto), email (email, correioeletronico). Sem coluna de identificação nem e-mail: lê por posição (ident, grupo, situação, peso, categoria, nome, e-mail), inclusive a 1ª linha. Coluna `cpf` com até 10 dígitos recebe zeros à esquerda. Sem coluna de situação: todos aptos.

## 16. Testes (`bj-voto-testes.js`): 97, todos aprovados

Cenário A (12 eleitores, sem papéis, grupo DF, 1 cargo com chapas 10 e 20):

1. Eleitor habilitado vota e recebe comprovante: `votar` devolve hash de 64 hex achável por `procurar`.
2. Segundo voto do mesmo eleitor é recusado ("já votou").
3. Eleitor com PIN incorreto é recusado.
4. Eleitor sem cadastro aprovado é recusado (status análise).
5. Eleitor de outro grupo é recusado (filtro de grupo).
6. Cadastro duplicado por identificação ou CPF é recusado.
7. Bloqueio após 5 erros de PIN: a 6ª tentativa, mesmo certa, é recusada.
8. Urna recusa autorização falsificada (assinatura `02`).
9. Urna recusa reuso de autorização válida (mesmo token e assinatura).
10. Votos simultâneos do mesmo eleitor: 6 chamadas paralelas, só 1 aceita.
11. Cédulas têm tamanho idêntico (voto válido, branco e nulo).
12. Urna não contém identidade do eleitor (uid, idH, nome, ident, pseudônimos).
13. Autorizador não recebe token nem voto.
14. Lista de participação não preserva ordem: faixa no formato hora e `LojaLocal.mapa` reordena alfabeticamente (testa a loja, não o `LojaMem` usado no sistema do teste).
15. Apuração bloqueada antes do encerramento.
16. Encerramento antecipado exige motivo.
17. Urna não aceita voto depois do encerramento.
18. Apuração bloqueada com menos de 3 guardiões.
19. Parte de guardião adulterada é recusada (compromisso `compD`).
20. Quaisquer 3 de 5 guardiões reconstroem a mesma chave.
21. Apuração confere com os votos dados (4, 4, 1 branco, 1 nulo, 10 cédulas).
22. Verificador independente confirma o resultado.
23. Verificador detecta resultado alterado.
24. Verificador detecta cédula alterada.
25. Verificador detecta cédula removida.
26. Verificador detecta cédula inserida (cópia reencadeada; pega pelo token repetido).
27. Eleição publicada não pode ser alterada.
28. Trilha administrativa adulterada é detectada.
29. Tentativas de fraude ficam registradas como incidentes (3 tipos esperados).

Cenário B (papéis exigidos):

30. Uma pessoa não acumula dois perfis (mesmo e-mail, mesmo escopo).
31. Administração não cria nem publica eleição.
32. Comissão não valida eleitor.
33. Validador não publica eleição.
34. Lista de aptos é importada com relatório (1 apto, 1 inapto, 1 duplicada, 1 inválida).
35. Lista de aptos bloqueada depois da abertura.
36. Eleitor inapto na lista é recusado.
37. Eleitor fora da lista é recusado.
38. Eleitor apto na lista vota.
39. Auditor não apura.
40. Guardião não apresenta a parte duas vezes.
41. Apuração exige partes apresentadas por guardiões distintos (só 1 apresentada).
42. Comprovante de participação não se liga à cédula (BJC aleatório, sem h, token, assinatura ou ct; faixa por hora).
43. Comprovante válido é localizado e falso é recusado.
44. Comissão apura com 3 guardiões (após 3 apresentações).

Cenário C (condomínio com peso):

45. Votação com peso exige lista.
46. Mesma pessoa cadastra mais de uma unidade quando o cenário permite (`multi`).
47. Unidade inadimplente não vota.
48. Urna recusa cédula com peso trocado.
49. Apuração soma os pesos (Sim 0,40; Não 0,25).
50. Critério absoluto usa o total de aptos (quórum 65%, 0,40 não passa de metade de 1,00).
51. Verificador independente confere voto com peso.
52. Regras: maioria, dois terços, unanimidade e empate (função `avaliar` isolada).
53. Lista aponta pesos raros (3 classes raras).

Cenário D (associação, acesso por código, programação automática):

54. Votação não abre antes do horário programado.
55. Votação abre sozinha no horário programado (`tick`).
56. CPF inválido é recusado.
57. Nome ou e-mail diferente da lista é recusado.
58. Código incorreto é recusado.
59. Identificação exige documento, selfie e declaração.
60. Eleitor vota com código, sem PIN nem biometria (sessão).
61. Relação de votantes só sai depois do encerramento.
62. Votação encerra sozinha no horário programado.
63. Não aceita voto depois do horário.
64. Relação mostra quem votou, sem o voto.

Cenário E (mesa e sessão permanente, `alterarVoto`, limites 60/90):

65. Mesa abre antes da data planejada e com prazo em minutos.
66. Mesa prorroga o prazo.
67. Votação fecha sozinha ao fim do prazo da mesa.
68. Segunda convocação usa o quórum próprio (0%).
69. Sem sessão permanente o voto não muda.
70. Próxima sessão acima de 60 dias é recusada.
71. Sessão permanente suspende a votação.
72. Sessão retoma sozinha na data marcada.
73. Eleitor ausente vota na sessão seguinte.
74. Na sessão permanente o voto pode ser alterado e só o último conta (3 eleitores, 4 depósitos).
75. Verificador aceita voto alterado e reconta.
76. Nome de chapa acima de 30 caracteres é recusado (`limNome`).

Cenário F (acesso por número/e-mail, consulta, conselho, equipe):

77. Acesso por número de inscrição da lista.
78. Eleitor só vê a votação em que consta (`visivel`).
79. Número fora da lista não recebe código.
80. Acesso pelo e-mail da lista.
81. Consulta exige identificação por padrão.
82. Consulta mostra inaptidão a quem confirma a identificação.
83. Consulta não localiza nome parcial.
84. Ocorrência abre avaliação com hora em segundos.
85. Comissão não decide a avaliação.
86. Decisão exige justificativa.
87. Conselho altera a inaptidão e a contagem acompanha.
88. Avaliação decidida não é decidida de novo.
89. Equipe própria afasta perfis de outra votação.
90. Cargos da categoria criam a equipe da votação (idempotente; guardião recebe x = 1).
91. Foto da candidatura fica bloqueada após a abertura e não muda o hash (a abertura é simulada gravando o status direto).

Cenário G (voto aberto e diversos):

92. Voto aberto só aparece depois do encerramento.
93. Registro nominal confere com a urna apurada.
94. Voto secreto não gera registro nominal.
95. Localizar pelo nome informa cadastro existente e pede grupo se houver duplicidade (inclui `habilitarPorLista`).
96. Comissão cria vagas em rascunho, mas não designa pessoas.
97. Salvar rascunho preserva a lista importada: **teste vazio** (`return true && !!a`, só confere que a eleição existe).

Testes fracos ou ausentes: 8 (assinatura trivial), 14 (testa a loja errada), 91 (status forçado), 97 (vazio). Não há teste de: desbloqueio de PIN, expiração do código (10 min), limite de 5 pedidos por hora, limite de 64 pesos, `aberto_vivo`, `procurar` com prefixo BJV, `relacaoVotantes` sem `listaVotantes`, verificador com `decisoes` adulteradas, número de cédulas maior que participação.

---

## 17. Achados (riscos e incoerências)

1. **Prova de voto (quebra de recibo):** o pacote público traz a chave privada `d` da eleição; o eleitor tem o código BJV (prefixo do `h` da sua entrada). Com os dois, qualquer pessoa acha a cédula e a decifra. O comprovante BJV + pacote permite provar o voto a terceiros (venda e coação). O ESPEC §4 diz "Comprovante não revela o voto". Correção: não publicar `d` (decifração em limiar com prova) ou contagem homomórfica/mixnet; ou não dar ao eleitor um código ligado à cédula.
2. **Guardiões de mentira:** as 5 partes ficam juntas no mesmo armazenamento; "apresentar a parte" é só um registro; `apurar` aceita qualquer lista de partes. O ESPEC admite reconstrução num ponto, mas não diz que as partes nunca saem do servidor.
3. **Pendente liga pseudônimo e token:** `bj-votacao-pendente:<eid>:<pseudônimo>` guarda `{tk, ass}` e, com `alterarVoto`, fica gravado até o fim. No servidor isso desfaz o anonimato. O ESPEC §16 já pede "token só no aparelho".
4. **Pseudônimo e hashes sem segredo:** `ps = sha('PART|eid|idH')`, `idH = sha('ID|grupo|ident')`, `cpfH = sha('CPF|cpf')`, `emH`. Quem tem a lista de identificações calcula todos; CPF tem 10^9 valores. No servidor: HMAC com chave secreta do domínio Identidade.
5. **Ordem e tempo:** a participação usa faixa, mas há hora exata na trilha (`concluirToken`), em `geos[].em` e no voto nominal. Num servidor único, os logs do nginx/PHP de "autorizar" e "depositar" (horário e IP) ligam eleitor e cédula na hora. Banco relacional com id serial ou `created_at` na participação também revela ordem.
6. **Enchimento de urna:** o Autorizador pode emitir tokens válidos para quem não votou (tem a chave privada RSA). O verificador não compara cédulas finais com participação nem publica a lista de participantes. `encerramento.participacao` não entra em hash algum.
7. **Verificador incompleto:** não confere `decisoes` (vencedor, quórum), `pesoAptos`, hash da lista, `zeresima`. O pacote é autorreferente; sem publicação externa de `cfgHash`, `genesis` e cabeça, um pacote todo refeito passa.
8. **Hash da configuração parcial:** `limNome`, `identTipo`, `consultaModo`, `limSessao`, `geo`, fotos, descrição e propostas não entram em `cfgHash`; `convocacao` e `limite` mudam depois da abertura (ficam só na trilha). Código de conferência tem 32 bits.
9. **Serialização JSON:** `cfgHash`, `genesis` (com `JSON.stringify(rsaP)`) e o hash da lista dependem de `JSON.stringify` do JS. O PHP (`json_encode`) escapa `/` e Unicode por padrão e trata números de outro jeito. Precisa de forma canônica escrita na spec.
10. **Cifra sem vínculo com o token:** AAD é só o `eid`. Uma cédula cifrada pode ser copiada para outro token válido (ataque de cópia, se a urna for pública durante a votação). Incluir token e peso no AAD.
11. **Criptografia escrita à mão:** RSA cega com FDH próprio (não RFC 9474), CRT sem verificação, BigInt sem tempo constante, Shamir próprio sem VSS (não identifica qual guardião errou), ECIES próprio. As primitivas WebCrypto (ECDH, HKDF, AES-GCM, PBKDF2, SHA-256, geração RSA) são maduras; a composição e o BigInt não.
12. **Incidentes fora da cadeia** e trilha com `new Date()` (não usa o relógio do sistema).
13. **Duas funções `elegivel`** (Identidade e Sistema) com regras diferentes; a da Identidade não é usada.
14. **`procurar` com prefixo:** o núcleo não aceita `BJV-...` (o `B` sobra como hex); a tela remove antes.
15. **Comparecimento x cédulas:** `participacao` conta autorizações; se o depósito falhar, comparecimento > cédulas. O boletim usa autorizações como comparecimento e contagem (não peso) mesmo em votação com peso.
16. **CPF sem dígito verificador no cadastro com PIN** (só no acesso por código).
17. **Consulta de situação devolve `uid`** do cadastro a quem consulta sem login.
18. **Limite de consultas global em memória**, não por IP; limites de código e PIN no mesmo armazenamento do cliente.
19. **Sem desbloqueio de PIN** em lugar nenhum ("Procure a comissão" sem ação correspondente).
20. **`K.cri` (compromissos)** declarada e nunca usada.

Incoerências com o ESPEC_VOTO.md:

- §15 diz que "a alteração de voto em sessão permanente ainda não está implementada"; o código implementa (`alterarVoto`, testes 74 e 75) e o §16 a descreve.
- §16 lista "voto nominal aberto" como não coberto; o código tem `sigilo: aberto` e `aberto_vivo` (testes 92 a 94) e o §16-A o descreve.
- §4 "Comprovante: código de depósito na urna" está desatualizado: já existe o BJC de participação (§13).
- §4 "Correlação por tempo: sem horário" convive com hora exata na trilha, em `geos` e no voto nominal (§16-A admite em parte).
- §3 "Guardiões em quórum liberam a chave": no código não liberam nada (achado 2).
- §3 "Urna nunca conhece pseudônimo" e "Autorizador nunca vê token": cumprido na urna e no mapa de participação, mas o pendente junta os dois no mesmo armazenamento (achado 3).
- §13 "comprovante emitido pelo Autorizador" (exigência de produção): no código é o `Sistema.votar` quem grava, junto com grupo e peso.
- §16 "A ata parcial mostra participação, nunca resultado parcial" convive com `aberto_vivo`, que mostra votos nominais em tempo real.
- §15 não cita o limite de 64 pesos nem a janela de 15 minutos para concluir após o código, nem 5 pedidos por hora, nem sessão de 1 hora; §4 não cita 120.000 iterações.
- §16-A cita critérios da foto; o código tem também `branco`, `sem-doc` e `sem-margem`, e a regra de 3 tentativas com ressalva está só na tela.
