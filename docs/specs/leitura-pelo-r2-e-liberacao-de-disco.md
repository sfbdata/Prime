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

## 5. Etapa C: liberar disco

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
| Cache enche o disco | teto de 512 MB, LRU e trava por SHA; os `.parcial-*` contam no teto; só o contêiner `php` hidrata |
| Exclusão LGPD não apaga no R2 | D6: liberar só depois da frente de exclusão |
| `listar()`/`excluirPrefixo()` não enxergam liberados | revisar cada chamador; o caso conhecido é a exclusão de pasta inteira, que precisa apagar também a linha de liberação e o objeto no R2 (frente D6) |
| Drive Via A, ZIP e IA exigem caminho | passam pelo `paraLeitura()` e recebem o cache (D3); testar um de cada |
| Arquivos sem referência no disco | ficam; fora do escopo |

## 8. Fora do escopo

R2 como origem primária · URL pré-assinada · `X-Accel-Redirect` · deduplicação dos 11.290 repetidos
(cerca de 12 GB, outra frente: o ganho no R2 é de custo, não de disco) · normalização de chave ·
categorias fora do espelho.
