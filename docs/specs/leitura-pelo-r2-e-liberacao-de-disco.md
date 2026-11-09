# Leitura pelo R2 (fallback) e liberação do disco da VPS

**Risco:** ALTO no rigor (perda de arquivo de cliente é irreversível), embora a camada seja de
armazenamento. Revisão independente antes de cada etapa ir para produção.
**Origem:** pedido do dono em 28/10/2026: a VPS está em 91% (87 de 96 GB), e o R2 deve virar a fonte
única o mais rápido possível, sem esperar a E4 inteira.
**Nome:** o Coordenador chama isto de "E3". Nas specs `e2-abstracao-de-storage.md` e
`p2-gravacao-dupla.md`, "E3" é backfill/normalização e "E4" é leitura pelo R2. Este documento cobre
só a leitura **de recuperação** (disco primeiro, R2 quando o disco não tem) e a liberação do disco. A
E4 completa (R2 como primário, URL pré-assinada, `PoliticaDeEntrega`) continua fora.

## 1. Estado de partida (28/10/2026)

| Fato | Valor |
|---|---|
| Espelho | 13 categorias ligadas, 23.641 `verificado`, 0 pendente, 0 `falhou`, 25,6 GB |
| Categorias não ligadas | `pasta_foto` (vazia) e `inteligencia_documento_juridico` (sem fonte de referências), e `recompensa_comprovante` e `tenant_logo` (chegaram depois da P2.5): ficam só no disco até serem ligadas |
| Disco da VPS | 96 GB, 87 usados; `jusprime_uploads_prod` 26 GB |
| Leitura hoje | toda pelo disco local (`ArmazenamentoEspelhado` repassa ao `ArmazenamentoLocal`) |
| Entrega ao usuário | `Shared/Http/EntregaDeArquivo::resposta()` → `MaterializadorDeArquivo::paraLeitura()` → `BinaryFileResponse(caminho)`, com Range e CSP sandbox. Nada é estático no nginx (`location ^~ /uploads/` reescreve para o PHP) |
| Quem exige caminho local | `EntregaDeArquivo`, ZIP (`ZipArchive::addFile`), Drive Via A (`ReconciliadorDePasta`), contador de páginas da IA (`LeitorDeDocumentosDaPasta:161`), compressão (`copiaGravavel`), `CalcularHashDosDocumentosCommand` |
| Quem usa só bytes | export PDF/DOCX/ODT, prévia Office, currículo, referências em peças, cópia entre pastas (`abrir`/`ler`) |
| R2 hoje | `EspelhoRemoto` só tem `enviar`, `consultar` e `existe` (trava em `EspelhoRemotoArquiteturaTest`) |

**Limpeza imediata, sem esta spec** (medida em 28/10; cada item só sai com prova por SHA e comando
do dono):

| Item | Tamanho |
|---|---|
| cache de build do Docker | 4 GB |
| `/opt/jusprime/backups/uploads_20260710_2016.tar.gz` | 11 GB |
| `jusprime_static_prod/_data/uploads`, escondido sob o mount do `uploads_prod` | 12 GB |
| `/opt/jusprime-acervo-download/pastas`, download do Drive de 29/05 | 14 GB |

Esses cerca de 40 GB resolvem a urgência. Esta spec é o caminho para o disco **não voltar** a encher.

## 2. Decisões

**D1. Disco primeiro, R2 só quando o disco não tem o arquivo.** Enquanto o arquivo está no disco, nada
muda (latência, Range, CSP). O R2 só é lido quando a cópia local foi liberada (§5) ou sumiu. Isso
deixa a etapa de leitura inofensiva sozinha: em produção, antes de liberar o primeiro lote, o caminho
novo não é exercido.

**D2. O R2 é lido só pelo servidor, sempre por proxy.**
- Sem URL pré-assinada, sem redirect e sem bucket público.
- Permissão, auditoria, `Content-Disposition` e CSP sandbox continuam na `EntregaDeArquivo`.
- A chave remota sai de `LayoutDeChaveRemota::chaveDe(ChaveDeArquivo)`, então o tenant vem da chave e
  nunca da URL.

**D3. Hidratação para uma área local com teto (cache de leitura).** Quem exige caminho recebe um
arquivo baixado do R2 para `var/espelho-cache/`.
- O arquivo só é entregue depois de conferido: o SHA-256 do baixado tem de ser igual ao da outbox
  `verificado`.
- O nome no cache é o SHA. A escrita é atômica (`.parcial-*` e depois `rename`), e o cache é por
  instância.
- Teto configurável (`ESPELHO_CACHE_MAX_BYTES`, padrão 512 MB no container, sem precisar da linha em
  produção), com descarte LRU por `atime`/`mtime` do arquivo de cache.
- O descarte nunca apaga arquivo usado nos últimos 30 minutos: o ZIP lê no `close()` e o
  `BinaryFileResponse` abre no `sendContent()`. O cache pode passar do teto por um tempo.
- Uma trava por SHA (`flock`) faz requisições simultâneas da mesma chave baixarem uma vez só.
- O cache fica na camada gravável do contêiner (`var/espelho-cache`) e some quando o contêiner é
  recriado. Isso é aceito.
- Motivo: um vídeo pedido com Range faz várias requisições; baixar a cada uma seria lento e caro.
- `paraLeitura()` devolve `ArquivoEmprestado` sobre o arquivo do cache. A cópia zero (INV-9) continua
  valendo para o disco de produção.

**D4. A liberação apaga do disco só o que está provado no R2, frio e com rollback.** Detalhe no §5.
Simulação por padrão, lotes pequenos, manifesto por lote e comando de volta.

**D5. A reconciliação passa a aceitar "liberado".** Um arquivo ausente no disco com registro de
liberação vira classe `liberado` (não alerta). O R2 continua conferido por inteiro: presença, tamanho
e SHA na fatia diária.

**D6. Exclusão continua como está, com uma condição para liberar.**
- Hoje o espelho nunca apaga no R2. A lixeira de 30 dias e a exclusão LGPD imediata são uma frente
  própria, decidida pelo dono em 23/10.
- Regra: nenhuma categoria tem a cópia local liberada antes de essa frente estar no ar.
- Motivo: depois de liberado, o R2 é a única cópia, e a exclusão pedida pela pessoa tem de apagar lá.
  Sem isso, o pedido LGPD deixaria o arquivo no R2.
- **Entregue em 03/11/2026 (PR da branch `r2-d6-exclusao`):** a exclusão grava `excluir`/`pendente` na
  outbox e o worker apaga o objeto no R2 (uma chave, conferida por HEAD); se o arquivo voltou ao disco,
  a exclusão vira `superado` e nada sai. A purga da lixeira (`app:documentos:purgar-lixeira`, 30 dias)
  roda às 0h50 (decisão do Samuel de 03/11/2026, que substitui a "lixeira para sempre" de 23/10). Etapa C: ao restaurar um lote, conferir que não há exclusão pendente da mesma chave, e
  a exclusão de um arquivo liberado tem de apagar também o registro de liberação.

## 3. Etapa A: leitura de recuperação

### 3.1 Contrato

`EspelhoRemoto` ganha `baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado,
string $sha256Esperado): void`.
- Grava em stream gravável, sem carregar tudo em memória (`getObject` do async-aws em blocos), e
  confere tamanho e SHA ao fim.
- Divergência ou ausência → `FalhaNoEspelhoRemoto`.
- O `EspelhoRemotoArquiteturaTest` é atualizado para a nova lista, e o docblock "ninguém lê conteúdo
  daqui" muda.
- `Accept-Encoding: identity` já está no `ClienteR2` (PR #503).

### 3.2 Onde entra

No `ArmazenamentoEspelhado`, nas leituras: `abrir`, `ler`, `existe`, `metadados`, `paraLeitura`,
`copiaGravavel`.

```
disco tem → igual a hoje
disco não tem, categoria espelhada e outbox com envio `verificado` desta chave →
    hidratar no cache (D3) → servir do cache
senão → o mesmo erro/null de hoje (ArquivoNaoEncontrado)
```

- `existe()` e `metadados()` sem o disco respondem pela outbox `verificado` (tamanho, SHA, MIME), sem
  ir ao R2. O R2 só é chamado ao hidratar.
- `copiaGravavel()` sem o disco hidrata e copia para a área temporária gravável. Ela grava de volta no
  disco, e isso é uma escrita normal, que reenvia ao espelho.
- `listar()`/`excluirPrefixo()` não mudam: listam e apagam só o disco. O uso de cada um é revisado no
  §7.
- Quem precisa saber o que está **no disco** recebe o `ArmazenamentoLocal` direto, sem cota e sem
  fallback: `ReconciliadorDoEspelho`, `ReplicadorDeArquivos` e `CarregadorDoAcervo`. Sem isso, a
  reconciliação deixaria de ver arquivo perdido e baixaria do R2 na fatia do SHA. Um teste de
  arquitetura trava essa fiação.
- `tarefa_anexo` fica fora do fallback até a prova R1 (§3.3).
- Falha do R2 na hidratação: erro 503 na entrega, com log de contagem, nunca 404 (o arquivo existe).
  Sem nova tentativa em linha.

### 3.3 Testes (antes do código)

- **Unit, `ArmazenamentoEspelhado` com `ArmazenamentoEmMemoria` e espelho dublê:**
  - disco tem: não chama o espelho;
  - disco não tem e há `verificado`: hidrata uma vez só (a segunda leitura sai do cache);
  - SHA errado no R2: falha e não deixa arquivo no cache;
  - categoria desligada: comportamento de hoje;
  - sem `verificado`: comportamento de hoje.
- **Cache:** teto e LRU; `.parcial-*` órfão é limpo; duas hidratações simultâneas da mesma chave
  resultam em um arquivo íntegro.
- **`EspelhoRemotoR2::baixar` contra o `S3Simulado`:** stream em blocos, objeto ausente, tamanho
  errado e SHA errado.
- **Functional, por categoria entregue pela `EntregaDeArquivo`:** apagar o arquivo do disco de teste,
  deixar `verificado` no dublê, e o download responde 200 com o mesmo SHA e o Range funciona.
- **Pendência da p2 §12 (R1 de Tarefa):** provar que a chave da leitura de anexo de tarefa usa o
  tenant da tarefa, e não o da mensagem, antes de ligar a leitura para `tarefa_anexo`.

## 4. Etapa B: registro de liberação e reconciliação

Uma migration (número pedido ao Coordenador), só aditiva: tabela `armazenamento_liberacao`.

| Coluna | Para quê |
|---|---|
| `id` | |
| `lote` | identificador do lote |
| `categoria`, `escopo`, `tenant`, `nome`, `chave_remota` | a chave |
| `sha256`, `tamanho_bytes` | o que foi provado no R2 |
| `liberado_em` | |
| `restaurado_em` | nulo enquanto está liberado |

- Índice único parcial em (`chave_remota`) onde `restaurado_em` é nulo.
- Fica fora do `TenantFilter`, com a mesma isenção aprovada para a outbox, e em `NAO_AUDITAVEIS`.
- Reconciliação: ausente no disco com linha aberta em `armazenamento_liberacao` → classe `liberado`
  (não alerta). Presente no disco **e** com linha aberta → classe `restaurado_sem_registro` (alerta
  leve: alguém regravou; fechar a linha).
- A leitura (§3) passa a aceitar como prova **ou** o `verificado` da outbox **ou** a linha de
  liberação. Assim a retenção de 90 dias da outbox não apaga a prova.

**Entregue em 03/11/2026 (branch `r2-registro-liberacao`):** migration `Version20261103180000`,
entidade só de mapeamento `LiberacaoDeArquivo` e `Liberacao\RegistroDeLiberacao` (DBAL: `abertaDe`,
`registrar`, `fechar`). O tenant fica em `escopo_tenant_id` (nulo = global), como na outbox.
- Reconciliação: o `liberado` só sai com o R2 conferido contra a linha (presença, tamanho e SHA do
  metadado, D5); faltando o objeto, sem SHA no metadado ou divergindo, a classe é a de sempre. No
  `restaurado_sem_registro`, uma divergência que exige ação ou o suspeito do Drive vale mais. O aviso
  leve sai na saída do comando, sem falhar a rodada.
- Exclusão: `excluir()` e `excluirPrefixo()` do `ArmazenamentoEspelhado` fecham a linha de liberação
  logo depois de gravar o `excluir` na outbox. Sem isso, a retenção de 90 dias apagaria o `excluir` e
  a linha voltaria a provar um arquivo excluído. Na purga por prefixo, os liberados (fora do disco,
  fora da listagem) só têm a linha fechada: o `excluir` deles na outbox fica para a etapa C (D6).
- Leitura: a linha de liberação só prova quando a outbox não tem linha **nenhuma** da chave. Qualquer
  linha mais nova (exclusão, reenvio pendente, falha) vale mais. Sem MIME na linha, `metadados()`
  responde `application/octet-stream` (a entrega adivinha o tipo pelo conteúdo).

## 5. Etapa C: liberar disco

### 5.0 Condições da 2ª revisão da Etapa A (fechar antes do primeiro lote)

1. **Espera da trava com prazo.**
   - Hoje o `flock` por SHA espera sem limite, e quem segura a trava pode levar até 600 s (`ClienteR2`).
   - No FPM (`pm.max_children` a conferir na VPS), poucas requisições do mesmo arquivo prendem todos os
     workers.
   - A espera tem de terminar antes dos 120 s do nginx, com 503 ao estourar. Também é preciso decidir
     o tempo máximo de hidratação dentro da requisição.
2. **Limite duro no cache.**
   - A proteção de 30 minutos deixa o cache crescer sem teto numa leitura em massa: ZIP da pasta,
     `CalcularHashDosDocumentosCommand`, Drive `ReconciliadorDePasta`, IA.
   - É preciso um piso de espaço livre (recusar com 503 abaixo dele), ou tirar esses leitores do
     fallback.
3. **Teste de fiação mais largo.** O `CalcularHashDosDocumentosCommand` mede o disco e ainda recebe a
   cadeia com fallback. O teste trava só `src/Shared/EspelhoRemoto`.
4. **Purga e exclusão.** Hoje, com o arquivo só no R2, a exclusão conta como "removido" e o objeto fica
   no R2. Isso é coberto pela condição D6.

**Estado em 03/11/2026 (branch `r2-leitura-limites`):** condições 1, 2 e 3 entregues.
- **1.** A espera da trava por SHA termina em 60 s (`CacheDeLeituraDoEspelho::ESPERA_MAXIMA_DA_TRAVA_EM_SEGUNDOS`,
  menor que os 120 s do nginx). Estourou: falha, e a entrega responde 503. **Tempo máximo da hidratação:**
  fica o teto do `ClienteR2` (600 s). Um arquivo que leva mais de 120 s para baixar recebe 504 do nginx,
  mas o PHP termina e publica no cache, e a próxima abertura sai do cache. Quem chega no meio espera no
  máximo 60 s. Arquivo tão grande assim é raro no acervo (o maior PDF da pasta é medido antes do 1º lote).
- **2.** Piso de 5 GiB de espaço livre no disco do cache (`PISO_DE_ESPACO_LIVRE_EM_BYTES`): o download que
  deixaria o disco abaixo do piso é recusado antes de começar (503). Vale para todo leitor em massa
  (ZIP, IA, Drive), sem tirar nenhum do fallback.
- **3.** `CalcularHashDosDocumentosCommand` recebe o `ArmazenamentoLocal` (`services.yaml`), travado em
  `EspelhoRemotoFiacaoArquiteturaTest`.
  - **Exceção de 09/11/2026 (decisão do Coordenador, branch `hash-fila-cursor-r2`):** com 12.982 PDFs sem
    páginas já liberados do disco, o `--paginas` passou a ler pelo `ArmazenamentoEspelhado` (`$espelhado`),
    porque o Ghostscript precisa do PDF inteiro. O download passa pelo cache de leitura (teto, piso de
    5 GiB, trava por SHA) e o operador roda em fatias de `--limite=200` com `--a-partir-de`. O modo hash
    continua lendo só o disco: o SHA do liberado vem da prova (outbox `verificado` ou liberação aberta),
    sem baixar. Os dois lados ficam travados no mesmo teste de fiação.

### 5.1 Comando

`armazenamento:liberar-disco --categoria=X [--frio-dias=90] [--lote=200] [--executar]`.
- Sem `--executar`, só simula e imprime contagens e bytes por categoria.
- Recusa quando:
  - a categoria não está ligada;
  - a P2 não cumpriu o critério de saída (N=7 dias de reconciliação limpa, sem `falhou`, medido pelos
    logs do agendador);
  - a frente de exclusão LGPD/lixeira (D6) não está no ar.

### 5.2 Quem é candidato (todas as condições)

1. Referenciado no banco: a mesma fonte da reconciliação. Arquivo sem referência fica no disco, porque
   é outra decisão.
2. A outbox tem `verificado` da chave atual, e o SHA local de agora é igual ao `sha256` da outbox.
3. Frio:
   - criado há mais de `--frio-dias` (data da referência no banco, ou `mtime` quando o banco não tem);
   - o arquivo não foi entregue nos últimos 30 dias, pelo `atime` do volume.

   **Antes de ligar**, medir a opção de montagem: com `noatime`, o critério de acesso fica só pela
   data de criação, e isso precisa ser registrado.
4. Não está em uma categoria com leitura que exige o disco e ainda não foi coberta pelos testes do §3.3.

### 5.3 Por arquivo, ao executar

1. Baixar do R2 para um temporário e conferir o SHA (`baixar`, §3.1). Isso prova o conteúdo e não só
   o metadado.
2. Gravar a linha em `armazenamento_liberacao` e dar commit.
3. Reconferir o SHA local (se mudou desde o passo 1: abortar este arquivo e fechar a linha).
4. Mover o arquivo local para `uploads/.liberados/<lote>/` (mesmo sistema de arquivos, `rename`).
   **Não apagar nesta execução.**
5. Ao fim do lote, imprimir contagens, bytes e o caminho do manifesto
   (`var/liberacao/<lote>.tsv`: chave, sha, tamanho).

### 5.4 Esvaziar e voltar atrás

- **Esvaziar:** `armazenamento:liberar-disco --esvaziar-lote=ID`, só depois de 7 dias do lote. Apaga
  `uploads/.liberados/<lote>/`, e é aí que o disco cai. Exige `--executar`, e quem roda é o dono.
- **Voltar atrás:** `armazenamento:restaurar-lote --lote=ID [--executar]`.
  - Se o lote ainda está em `.liberados`: devolve por `rename`.
  - Se já foi esvaziado: baixa do R2 e confere o SHA.
  - Nos dois casos, fecha as linhas (`restaurado_em`).
- **Restaurar um arquivo:** `--chave=` para restaurar só ele.

### Estado em 03/11/2026 (branch `r2-liberar-disco`): liberar e restaurar, sem esvaziar

Entregues `armazenamento:liberar-disco` e `armazenamento:restaurar-lote`. O `--esvaziar-lote` **não
existe** e continua bloqueado: nada é apagado do disco nesta etapa.

- **Onde ficam os liberados:** `<uploads_dir>/.liberados/<lote>/<caminho relativo ao uploads_dir>`
  (em produção, `public/uploads/pastas/.liberados/`). Fica dentro da raiz das duas categorias aceitas,
  então o `rename` não troca de sistema de arquivos (o comando confere o dispositivo e recusa se
  trocar). O caminho sai do `ResolvedorDeCaminhoLocal::caminhoNosLiberados()`, e o disco é tocado só
  pela `Shared/Armazenamento/AreaDeLiberados`. O lote é `AAAAMMDD-HHMMSS` no horário de Brasília.
- **P2 (7 dias de reconciliação limpa):** o sistema não guarda as rodadas boas da reconciliação; só a
  `falha_agendada` (as que falharam). Sem tabela nova, a execução exige `--confirmo-reconciliacao-limpa`
  (o operador confere os logs do agendador) e, além disso, recusa sozinha quando há falha da
  `armazenamento:reconciliar-espelho` em `falha_agendada` nos últimos 7 dias, ou envio da categoria em
  `falhou` na outbox. A D6 já está no ar: sem checagem.
- **Critério de acesso (`atime`):** com `relatime` (o padrão do ext4 e o que o contêiner de dev mostra),
  qualquer leitura renova o `atime` uma vez por dia. A fatia semanal do SHA da reconciliação lê cada
  arquivo uma vez por semana, então o critério "sem acesso há 30 dias" deixa **tudo** de fora. O
  critério fica ligado por padrão, como pede o §5.2, e `--sem-acesso` o desliga (fica só a data do banco
  ou o `mtime`). A medição do próprio comando não aquece o arquivo: o `atime` volta ao que era depois do
  SHA. **Antes do primeiro lote:** medir a montagem do volume na VPS e decidir se o lote roda com
  `--sem-acesso`.
- **Data do banco:** documento da pasta usa o mais novo entre `uploaded_at` e `modificado_em`; sem data
  no banco, vale o `mtime`. Várias linhas da mesma chave: vale a mais nova.
- **Imagens do editor:** a categoria é aceita, mas não tem referência no banco (mora no HTML da peça),
  então nunca há candidato (item 1 do §5.2). A simulação avisa.
- **Exclusão de um liberado (D6, etapa C; revisão de 03/11):** `excluir()` do `ArmazenamentoEspelhado`
  apaga a cópia em `.liberados/<lote>/` (pela `AreaDeLiberados`) e depois fecha a linha; é a mesma
  exclusão de sempre, não o esvaziamento. Na purga por prefixo, cada linha aberta do escritório na
  categoria tem a cópia apagada e, com a categoria espelhada, o `excluir` registrado na outbox (o objeto
  sai também do R2). Falha ao apagar a cópia fica no log, e a linha fecha mesmo assim. A restauração
  não devolve ao disco uma chave com exclusão registrada depois do último envio.
- **Lixeira fora:** documento com `excluido_em` preenchido nunca é candidato (motivo `na_lixeira`); a
  purga dos 30 dias o apaga.
- **Risco anotado (caminho repetido):** em produção (03/11/2026) nenhum `caminho_arquivo` se repete em
  `pasta_documento`. Se uma cópia de documento um dia repetir o caminho de outro, as duas linhas apontam
  para o mesmo arquivo e a mesma linha de liberação: excluir um apagaria a cópia liberada e fecharia a
  linha do outro.
- **Por arquivo:** além dos passos do §5.3, o SHA da cópia é medido de novo **depois** do movimento;
  se mudou (regravação no meio), a cópia volta ao lugar e a linha fecha. Com o lugar já ocupado de novo,
  a cópia fica presa nos liberados com a linha aberta (`copia_presa_nos_liberados`). Destino ocupado, SHA local
  diferente, R2 que não confere: pula e a linha fecha. Falha depois de mover deixa a cópia nos liberados
  e a linha aberta (o restaurar a devolve). Esses dois casos entram no manifesto com a coluna
  `situacao`; os liberados entram como `liberado`.
- **Execução única:** liberar e restaurar (com `--executar`) dividem a trava `armazenamento-liberacao-do-disco`
  (tabela `lock_keys`, vale entre contêineres), renovada a cada arquivo. Manifesto que não grava: o
  resumo sai mesmo assim e o comando termina com falha.

### Estado em 04/11/2026 (branch `r2-esvaziar-lote`): esvaziar

- **Comando próprio:** `armazenamento:esvaziar-lote --lote=ID [--executar --confirmo-backup-testado]`,
  em vez de `liberar-disco --esvaziar-lote` (o liberar exige `--categoria` e recusas que aqui não se
  aplicam). Simula por padrão, sem baixar do R2.
- **Decisão do Samuel (04/11):** backup pelo caminho 2 (cópia de base nova a cada 90 dias, a anterior
  sai; `diario/` com 90 dias). O esvaziamento deixou de esperar a decisão LGPD; exige
  `--confirmo-backup-testado` (cópia diária rodando e teste de restauração do backup feito).
- **Provas por cópia, na hora:** linha com mais de 7 dias; sem exclusão registrada; original fora do
  lugar; o último registro da outbox é envio `verificado` com o SHA e o tamanho da linha (ou a outbox não
  tem linha nenhuma da chave, porque a limpeza de 90 dias apagou o envio: a mesma regra da leitura); a cópia nos
  liberados tem o SHA da linha; e, com `--executar`, um download inteiro do R2 com o SHA e o tamanho da
  linha. Falhou uma: a cópia fica e o motivo entra na contagem (falha de disco ou do R2 vai
  para o log com o id da linha, sem o nome do arquivo).
- **A linha continua aberta** (sem coluna nova): esvaziado = linha aberta sem cópia nos liberados. A
  leitura segue pelo R2 e o `restaurar-lote` baixa do R2 (já previsto). A trava é a mesma da liberação.
- **Cópia de base nova do backup:** o arquivo esvaziado não está mais no disco, então a base nova feita
  do disco não o tem. O roteiro copia, dentro do bucket do backup, os arquivos das linhas abertas da
  base antiga para a nova antes de apagar a antiga (`bluejus-coordenacao/r2-backup-roteiro.md`).
- **Custo da simulação:** a categoria é filtrada antes de qualquer consulta; as datas saem por bloco de
  mil ids, só das linhas da categoria; a saída mostra o tempo da seleção. Erro do banco na seleção sai com
  mensagem genérica (os parâmetros trazem o nome do arquivo).
- **Restaurar:** devolve por `link` e `unlink` da cópia (o `link` falha com o original presente, então
  nunca sobrescreve, nem numa corrida com um upload); fora dos liberados, baixa do R2 para um parcial em
  `.liberados/.parciais` (mesmo sistema de arquivos, fora do diretório dos documentos), confere SHA e
  tamanho da linha e publica do mesmo jeito. Original presente com o SHA da linha: fecha a linha
  (`ja_no_lugar`). Com outro SHA: pula e avisa, e a linha fica aberta. `--baixar-para=PASTA` (com `--chave`) baixa para a pasta do operador e
  imprime OK ou DIVERGE, sem tocar no disco do sistema nem na linha.
- **Tela:** só contagens e bytes. A chave remota (que traz o nome do arquivo) fica só no manifesto.

### 5.5 Antes do primeiro lote

- Cópia complementar do backup (P0d) no `bluejus-backup-uploads`, como P0/P0b/P0c: o R2 de backup
  continua separado do espelho.
- Primeiro lote de 50 arquivos de `pasta_documento`. Conferir a entrega de 3 deles pelo navegador; o
  smoke é do dono.
- Depois, lotes crescentes, de madrugada.

### 5.6 Ordem das categorias e ganho esperado

`pasta_documento` (cerca de 25 GB, quase todo o ganho) e depois `pasta_imagem_editor`. As outras
somam menos de 100 MB e ficam no disco, porque o ganho não paga o risco.

## 6. Prazos

| Etapa | Duração | No ar |
|---|---|---|
| Limpeza imediata (§1) | horas, depois da prova por SHA | 28 e 29/10 |
| A: leitura de recuperação | cerca de 3 dias, mais a revisão | por volta de 04/11 |
| B: registro e reconciliação | cerca de 1 dia, com migration | junto com A ou logo depois |
| Critério de saída da P2 (N=7) | | 04/11, se a reconciliação ficar limpa desde 28/10 |
| Frente de exclusão LGPD/lixeira (D6) | frente própria | condição para C |
| C: primeiro lote | | depois de A, B, N=7 e D6, por volta de 06/11 a 10/11 |

## 7. Riscos e como cada um fica coberto

| Risco | Cobertura |
|---|---|
| Arquivo liberado que o R2 não tem | download completo com SHA antes de mover; `.liberados` por 7 dias; backup P0d |
| R2 fora do ar | 503 na entrega só dos liberados; o disco atende o resto; o alerta da reconciliação avisa |
| Chave com tenant errado (e2 :473) | testes de leitura por categoria × tenant; R1 de Tarefa (§3.3) |
| Cache enche o disco | teto de 512 MB, LRU e trava por SHA; os `.parcial-*` contam no teto. php, worker e scheduler têm cache próprio (três tetos). Leitura em massa ainda não tem limite duro: ver §5.0 |
| Exclusão LGPD não apaga no R2 | D6: liberar só depois da frente de exclusão |
| `listar()`/`excluirPrefixo()` não enxergam liberados | revisar cada chamador; o caso conhecido é a exclusão de pasta inteira, que precisa apagar também a linha de liberação e o objeto no R2 (frente D6) |
| Drive Via A, ZIP e IA exigem caminho | passam pelo `paraLeitura()` e recebem o cache (D3); testar um de cada |
| Arquivos sem referência no disco | ficam; fora do escopo |

## 8. Fora do escopo

R2 como origem primária · URL pré-assinada · `X-Accel-Redirect` · deduplicação dos 11.290 repetidos
(cerca de 12 GB, outra frente: o ganho no R2 é de custo, não de disco) · normalização de chave ·
categorias fora do espelho.
