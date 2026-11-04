# Painel de artigos dos blogs (área do Master)

Risco: **MÉDIO** (publica conteúdo público no bluejus.com.br e mexe na infraestrutura de produção: um
contêiner novo e uma chave de leitura do repositório dos sites). Sem dado de cliente.
Branch desta spec: `spec-painel-artigos`. Pedido do Samuel em 03/11/2026, para o Farlei usar.

## Objetivo

O Farlei escreve, revisa, agenda e publica os artigos do **Blog BlueJus** (`/blog`) e do **Blog BlueJus
Grátis** (`/gratis`) dentro do BlueJus, no Painel do Master, sem PR e sem a máquina do Samuel. Ao clicar
em **Publicar**, o servidor gera os blogs de novo e o artigo aparece no site em poucos minutos.

## Como é hoje (o que a spec respeita)

- Repositório `sfbdata/sites-bluejus` (local: `~/projetos/sites`), três projetos Astro 7 estáticos:
  `site/`, `blog/` (base `/blog`) e `blog-gratis/` (base `/gratis`).
- Artigo = um `.md` em `src/content/artigos/` de cada blog, validado pelo `src/content.config.ts` (zod).
  O formato é **o mesmo nos dois blogs** (mesmas 12 categorias, mesmo autor `equipe-editorial-bluejus`).
- Frontmatter: `titulo`, `tituloCurto`, `tituloSeo` (até 60), `descricao` (até 160), `linhaFina`, `resumo`,
  `slug` (`^[a-z0-9]+(-[a-z0-9]+)*$`), `categoria` (enum de `src/dados/categorias.ts`), `autor` (enum de
  `src/dados/autores.ts`), `revisor {nome, oab}` (opcional, só com revisão jurídica real), `publicadoEm`,
  `atualizadoEm` (opcional), `leitura`, `imagem` + `imagemAlt` (imagem exige alt), `referencias
  [{texto, url, rotulo}]`, `relacionados [slug]`, `destaque`, `rascunho`.
- Corpo em Markdown com `##`/`###` e as diretivas do desenho (`remark-directive` + `src/lib/diretivas.mjs`):
  `:::aviso`, `:::etapas` (lista numerada com **título.** em negrito), `:::checklist`, `::cta-meio`,
  `::leia-tambem{artigo=slug}` (só aparece se o artigo citado estiver publicado).
- `publicadoEm` no futuro = agendado, mas só entra no **próximo build**: um site estático não muda sozinho.
- Publicação: `scripts/publicar.sh --enviar` na máquina do Samuel (worktree limpa do `origin/main`,
  `npm ci` + `npm test` + `npm run build` nos 3 projetos, `rsync --delete` para
  `bluejus:/opt/jusprime/sites-publicados/{site,blog,gratis}`). O nginx de produção serve essa pasta
  (`./sites-publicados:/var/www/sites:ro`, PR #580).

## Decisão de base: onde mora o artigo

**O banco do BlueJus passa a ser a fonte dos artigos.** O repositório dos sites continua dono do **código**
(layout, componentes, categorias, autores, testes); a pasta `src/content/artigos/` vira gerada na hora do
build a partir do que o BlueJus exporta. Os artigos que hoje estão no repositório (1 no blog) são importados
uma vez para o banco (fatia P2).

Por quê: rascunho, agendamento, revisão e "publicar agora" são estado de aplicação (quem, quando, qual
versão); no git eles viram PRs que o Farlei não opera. O build continua o mesmo (Astro + zod + testes do
repositório), então um artigo que o painel aceita e o build recusa é pego antes de ir ao ar.

## 1. Modelo de dados (domínio novo `App\Blog`, global, sem tenant)

Global como `ConfiguracaoGlobal` e `TemaConfig`: conteúdo da plataforma, escrito só pelo Master. Auditável
(`Auditavel`, escopo de plataforma via `EscopoDeAuditoriaDePlataforma`).

**`ArtigoDoBlog`** (`artigo_blog`)

| Campo | Tipo | Regra |
|---|---|---|
| `id` | int | PK |
| `slug` | string(120), único | regex do zod; trocar o slug de artigo publicado pede confirmação (link quebra) |
| `blogs` | enum `BlogsDoArtigo`: `normal`, `gratis`, `ambos` | em qual blog sai |
| `titulo`, `tituloCurto`, `linhaFina`, `resumo` | string / text | obrigatórios |
| `tituloSeo` | string(60) | obrigatório, contador na tela |
| `descricao` | string(160) | obrigatório, contador na tela |
| `categoria` | string(60) | um dos 12 slugs (lista no BlueJus, ver D6) |
| `autor` | string(60) | um dos autores (hoje 1) |
| `revisorNome`, `revisorOab` | ?string | os dois ou nenhum |
| `leitura` | string(20) | calculado pelo número de palavras (200/min), editável |
| `imagemChave`, `imagemAlt` | ?string | imagem de compartilhamento; imagem exige alt |
| `referencias` | json `[{texto,url,rotulo}]` | url `http(s)` válida |
| `relacionados` | json `[slug]` | só slugs que existem |
| `destaque` | bool | |
| `corpoHtml` | text | o que o editor guarda (fonte da edição) |
| `corpoMarkdown` | text | gerado do HTML a cada salvar (fonte do build); nunca editado à mão |
| `situacao` | enum `SituacaoDoArtigo`: `rascunho`, `agendado`, `publicado`, `despublicado` | ver ciclo |
| `publicarEm` | ?datetime (Brasília) | data de publicação/agendamento; vira `publicadoEm` |
| `publicadoEm`, `atualizadoEm` | ?datetime | `atualizadoEm` só muda em republicação com texto novo |
| `criadoPor`, `alteradoPor`, `criadoEm`, `alteradoEm` | | |

**`ImagemDoArtigo`** (`artigo_blog_imagem`): `id`, `artigo` (FK, cascade), `chave` (R2), `nomeOriginal`,
`mime`, `larguraPx`, `alturaPx`, `tamanhoBytes`, `alt`, `criadaEm`. Uma linha por imagem enviada ao corpo
ou à capa.

**`PublicacaoDosBlogs`** (`publicacao_blogs`): `id`, `pedidaEm`, `pedidaPor` (null = agendador),
`motivo` (`publicar`, `agendado`, `despublicar`, `manual`), `situacao` (`na_fila`, `gerando`, `no_ar`,
`falhou`), `iniciadaEm`, `terminadaEm`, `commitDosSites` (hash do código usado), `log` (últimas 200 linhas),
`artigos` (json com os slugs que entraram). É o histórico da tela e o que impede duas gerações ao mesmo tempo.

**Ciclo do artigo**

```
rascunho ──Agendar(data futura)──> agendado ──(agendador, na hora)──> publicado
    │                                 │
    └──────────Publicar agora─────────┴──────────────────────────────> publicado
publicado ──Despublicar──> despublicado ──Publicar──> publicado
qualquer um ──Excluir──> (some; publicado pede despublicar antes)
```

Salvar um artigo **publicado** guarda o texto mas não muda o site até **Publicar de novo** (aviso "Alterações
ainda não publicadas"). Assim o Farlei corrige sem medo de ir ao ar no meio da edição.

## 2. Telas (padrão do Master)

Entrada: nova seção **"Artigos dos blogs"** no Painel do Master (`painel.js`, `SECS`, ícone
`bi-journal-richtext`, como as seções externas `com`, `ind`, `notif`, `roab`), com dados de
`/master/blog/*` (JSON, `ROLE_SUPER_ADMIN`, CSRF no header `X-CSRF-Token`). O editor abre numa página
própria `/master/blog/artigos/{id}` (Twig, layout do Master), porque o TinyMCE e os campos de SEO não
cabem num cartão do painel.

**2.1 Lista** (seção do painel)
- Barra: busca (título, slug), filtro por situação (Todos, Rascunhos, Agendados, Publicados,
  Despublicados), filtro por blog (Normal, Grátis, Os dois), botão **Novo artigo**.
- Tabela: Título (com selo DESTAQUE), Blog, Categoria, Situação (pílula), Data (agendado para / publicado em),
  "Alterações não publicadas" quando houver, ações (Editar, Ver no site, Duplicar).
- Faixa da última publicação: "No ar desde 03/11/2026 14:05" ou "Gerando os blogs..." ou "A última
  publicação falhou: ver detalhes", com o histórico de `PublicacaoDosBlogs` (as 20 últimas).

**2.2 Editor** (página própria), duas colunas:
- Esquerda: Título, Linha fina, o **editor rico** do corpo e, abaixo, Referências (linhas texto, URL,
  rótulo, com adicionar e remover).
- Direita (cartões): **Publicação** (situação, Blog: Normal / Grátis / Os dois, data e hora de publicação,
  botões Salvar rascunho, Agendar, Publicar agora, Despublicar, Excluir); **SEO** (Título SEO com contador
  60, Meta description com contador 160, Slug com "gerar do título", prévia do resultado do Google com a URL
  `bluejus.com.br/blog/{categoria}/{slug}`); **Classificação** (Categoria, Autor, Revisor nome + OAB,
  Leitura, Destaque); **Imagem de compartilhamento** (envio, alt obrigatório, prévia); **Relacionados** (até
  3, busca por título entre os artigos existentes); **Resumo** (cartões) e **Título curto** (trilha).
- **Pré-visualizar**: abre o artigo como ficará, renderizado pelo próprio Astro (ver 6, "prévia"), ou, no
  MVP, uma prévia aproximada com o CSS do blog.
- Validação igual à do zod antes de Agendar/Publicar (mesmas regras, mesmas mensagens em português), para o
  build nunca ser o primeiro a recusar. Rascunho salva incompleto.

Sem desenho do Claude Design para estas telas: segue o padrão visual do Master (decisão D1).

## 3. Editor: TinyMCE e a conversão para as diretivas

**Recomendação: TinyMCE 7.9.2, que o sistema já tem** (`app/public/js/tinymce/`, usado no Peticionar, licença
GPL autohospedada). Sem dependência nova, o Farlei já conhece a barra. Alternativa considerada: TipTap
(ProseMirror, JSON → Markdown determinístico): conversão mais limpa, mas é lib nova, build JS e mais código.

**Configuração restrita** (o blog tem pouco formato, e menos formato = conversão sem perda):
- Barra: Parágrafo, Seção (`##`), Subseção (`###`), negrito, itálico, link, lista, lista numerada, citação,
  imagem, desfazer/refazer, e um menu **Blocos BlueJus** com: Caixa de atenção, Etapas, Checklist, Chamada
  para criar conta, Leia também (escolhe o artigo).
- `valid_elements` limitado a `p, h2, h3, strong, em, a[href], ul, ol, li, blockquote, img[src|alt], br`
  mais os blocos abaixo; colar do Word limpa o resto (`paste_as_text` desligado, `invalid_elements` e
  `paste_postprocess` tirando estilo e classe).
- Os blocos são elementos não editáveis por fora e editáveis por dentro (`noneditable` + `contenteditable`):

| Bloco no editor (HTML guardado) | Markdown gerado |
|---|---|
| `<div data-bj="aviso">…</div>` | `:::aviso` … `:::` |
| `<div data-bj="etapas"><ol><li><strong>Título.</strong> texto</li></ol></div>` | `:::etapas` + `1. **Título.** texto` + `:::` |
| `<div data-bj="checklist"><ul><li>…</li></ul></div>` | `:::checklist` + `- …` + `:::` |
| `<div data-bj="cta-meio"></div>` | `::cta-meio` |
| `<div data-bj="leia-tambem" data-artigo="slug"></div>` | `::leia-tambem{artigo=slug}` |
| `<img src="/master/blog/imagens/{id}" alt="…">` | `![alt](./imagens/{id}.{ext})` |

**Conversão no servidor**, a cada salvar: `HtmlDoArtigoParaMarkdown` (serviço do domínio Blog), um
conversor próprio sobre `DOMDocument`, sem dependência nova (ajuste da P1: `composer.json` e `composer.lock`
são arquivos quentes). Antes de converter, o HTML passa por um sanitizador de lista branca (o mesmo conjunto
do `valid_elements`): o que o TinyMCE deixa passar não é confiável só por vir do navegador.

**Segurança do conversor (Coordenador, 03/11).** O Astro repassa o HTML cru que estiver no Markdown, então
o conversor nunca emite HTML: tag fora da lista branca vira só o texto dela (`script` e `style` somem
inteiros); `<`, `>` e `&` do texto saem escapados; `href` só aceita http(s), `mailto` e caminho relativo
(`javascript:`, `data:` e `vbscript:` caem, inclusive com maiúsculas ou espaço antes); `src` de imagem só
aceita `/master/blog/imagens/{id}`. Coberto por `ConversorXssTest`.

**Imagens no build (Coordenador, 03/11).** `/master/blog/imagens/{id}` só vale no editor (exige login). O
Markdown exportado usa `./imagens/{id}.{ext}` e o builder da P4 copia o arquivo para o lado do artigo no
repositório dos sites (seção 4): o site continua estático, sem rota pública no PHP.
Testes de ida: um HTML de cada bloco vira exatamente o Markdown da tabela; um artigo real do repositório
(`controle-de-prazos-processuais.md`) importado e reexportado sai equivalente (fatia P2).

## 4. Imagens (R2, skill `armazenamento-de-arquivos`)

- Categoria nova `CategoriaDeArquivo::BLOG_IMAGEM = 'blog_imagem'`, escopo `EscopoDeArquivo::global()`
  (sem escritório). Fábrica `App\Blog\Armazenamento\ChavesDoBlog::novaImagem(ext)` (ajuste da P3: a
  `ImagemDoArtigo` nasce com a chave, e o escopo global não tem dona a consultar) e `::imagem(ImagemDoArtigo)`.
  Nasce **desligada** no espelho do R2: entra em `ARMAZENAMENTO_ESPELHO_CATEGORIAS` só quando o Samuel ligar, e
  antes disso a fonte `artigo_blog_imagem` precisa entrar em `FontesDeReferencia` (carga do acervo).
- Envio: `POST /master/blog/artigos/{id}/imagens` (o `images_upload_handler` do TinyMCE e o campo da capa).
  `FonteDeUploadHttp::de()` (extensão pelo conteúdo), só `jpg`, `png`, `webp`, até 5 MB e até 10000 px de lado
  (imagem gigante derruba o build); grava pela chave, mede largura/altura, e devolve `{id, url}`. Sem compressão no envio: o Astro otimiza no build.
- Exibição no editor: `GET /master/blog/imagens/{id}` (Master), `EntregaDeArquivo::resposta(…, inline: true)`.
- Exclusão: apagar a imagem ou o artigo remove o arquivo **depois do COMMIT** (`RemocaoAposTransacao`).
  Imagem sem uso no corpo nem na capa é limpa ao salvar, **depois de 1 hora** de enviada (carência da P3: o
  salvar que sai enquanto a capa sobe, ou numa segunda aba, não apaga a imagem recém-enviada).
- Capa: `imagemChave` guarda o **id** da `ImagemDoArtigo` (ex.: `"12"`), não a chave do armazenamento; só vale
  id de imagem do próprio artigo (422 no salvar). O pacote da P4 lê esse id para gerar `./imagens/{id}.{ext}`.
- No build, o pacote leva cada imagem para `artigos/{pasta}/imagens/{id}.{ext}`, ao lado dos `.md` do blog
  (contrato na seção 6.1), onde o `image()` do zod e o `<Image>` do Astro já sabem tratar.

## 5. Permissão

Só o Master: `#[IsGranted('ROLE_SUPER_ADMIN')]` em todos os controllers do domínio Blog (página do editor,
JSON, imagens, publicar), como o resto do Painel do Master (domínio sem módulo de permissão,
`app/src/CLAUDE.md`). CSRF em todo POST. Sem tenant, sem `PermissionChecker`. O builder (seção 6) não é
alcançável pela internet.

## 6. Publicar no servidor, sem a máquina do Samuel

O que qualquer caminho tem que fazer: pegar o **código** dos sites (`origin/main`), pôr os **artigos do
banco** em `src/content/artigos/` dos blogs, rodar `npm ci`, `npm test`, `npm run build` e trocar
`sites-publicados/{blog,gratis}` (o `site/` só muda quando o código muda).

### Opção A: contêiner `builder` na VPS (recomendada)

- Serviço novo no `docker-compose.prod.yml`: `blog_builder`, imagem `node:22-alpine` + `git` + `rsync`,
  sem porta, só na rede interna. Volumes: `./sites-publicados` (escrita), `publicacao_blogs` (volume
  compartilhado com o php e o worker, onde chegam os pedidos) e um cache do `npm`.
- Chave **deploy key somente leitura** do `sfbdata/sites-bluejus` montada no builder (arquivo fora do git,
  como o `.env.prod`). Ele faz `git fetch` do `main`; nunca escreve no repositório.
- Fluxo: o Master clica **Publicar** → `PublicarArtigoUseCase` muda a situação e cria uma
  `PublicacaoDosBlogs` `na_fila` → o worker (Messenger, fila `async`) monta o **pacote**:
  `pedidos/{id}/artigos/{blog}/{slug}.md` + imagens + `pedido.json` → o builder, que vigia a pasta (laço de
  5 s, um pedido por vez), gera numa pasta temporária, roda os testes do repositório e, só se tudo passar,
  faz `rsync --delete` para `sites-publicados/{blog,gratis}` → escreve `resultado.json` (situação, commit,
  log) → o worker lê e atualiza a `PublicacaoDosBlogs`. Falha não toca no site que está no ar.
- Prévia (fase 2): o mesmo builder gera um blog com o artigo em rascunho numa pasta `previa/{token}`, que o
  nginx serve só com o cookie de sessão do Master (`auth_request` para o BlueJus).
- Prós: tudo dentro da VPS, nenhum segredo da VPS sai para o GitHub, não depende do GitHub para publicar,
  ~1 a 2 min por publicação (com o cache do npm). Contras: o build usa CPU/RAM da VPS (Astro de 2 blogs é
  leve; limitar `cpus: 1`, `mem_limit: 1g`), e é um contêiner a mais para manter.

### 6.1 Contrato do pacote (P4, 03/11)

Vale para os dois lados: o BlueJus (worker que monta o pacote e lê o resultado) e o repositório dos sites
(build que aceita a pasta de artigos de fora). Versão do contrato: `1`.

**Pastas e permissões**

- Volume nomeado `publicacao_blogs`, montado em `/var/publicacao-blogs` no `worker` e no `scheduler` (o php não
  precisa) e em `/pedidos` no `blog_builder`. Parâmetro do BlueJus: `publicacao_blogs_dir` (em dev e teste,
  `%kernel.project_dir%/var/publicacao-blogs`).
- O `blog_builder` roda como `33:33` (o `www-data` do php), para os dois lados lerem e escreverem os mesmos
  arquivos. `HOME=/cache` (volume `blog_builder_cache`: clone do repositório, cache do npm e `known_hosts`).
- `./sites-publicados` montado em `/sites-publicados` (escrita) no `blog_builder`; o nginx continua `:ro`.
- Deploy key em `/opt/jusprime/secrets/sites_deploy_key`, montada só leitura em `/chave` no `blog_builder`.

**O pedido (BlueJus escreve)**

```
pedidos/{id}/                  id = PublicacaoDosBlogs.id
  pedido.json
  artigos/blog/{slug}.md        artigos do Blog BlueJus (situação publicado)
  artigos/blog/imagens/{id}.{ext}
  artigos/blog-gratis/{slug}.md artigos do Blog BlueJus Grátis
  artigos/blog-gratis/imagens/{id}.{ext}
```

- O pacote leva **todos** os artigos `publicado` de cada blog, não só o que mudou: o build gera o blog inteiro
  e o `rsync --delete` tira do ar o que saiu (despublicado ou excluído).
- Escrita atômica: o worker monta em `pedidos/.{id}.montando/` e no fim faz `rename` para `pedidos/{id}/`. O
  builder ignora nome que começa com `.`.
- `pedido.json`:

```json
{ "versao": 1, "id": 12, "pedidaEm": "2026-11-03T14:05:00-03:00", "motivo": "publicar",
  "artigos": { "blog": ["controle-de-prazos-processuais"], "blog-gratis": [] } }
```

- Artigo com `blogs = ambos` sai nas duas pastas. Na cópia do `blog-gratis`, o frontmatter leva
  `canonical: "https://bluejus.com.br/blog/{categoria}/{slug}"` (D5). Nos outros casos a chave não sai.

**O builder (laço, um pedido por vez)**

1. A cada 5 s, pega o menor `{id}` em `/pedidos` sem `resultado.json` e sem nome começando com `.`.
2. Escreve `iniciado` (data ISO) na pasta do pedido.
3. `git fetch` do `main` no clone de `/cache` (`GIT_SSH_COMMAND="ssh -i /chave -o
   StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/cache/known_hosts"`) e cópia limpa numa pasta
   temporária. Guarda o hash do commit.
4. Para `blog` e depois `blog-gratis`: `npm ci`, `npm test` (os testes do repositório, **sem** `ARTIGOS`) e
   `ARTIGOS=/pedidos/{id}/artigos/{pasta} npm run build`.
5. Só se tudo passar nos dois: `rsync -a --delete dist/ /sites-publicados/{blog|gratis}/` (`blog-gratis` publica em
   `gratis`). Falha em qualquer passo: nada é copiado, o site no ar fica como estava.
6. Escreve `resultado.json` (em `resultado.json.tmp` e `rename`):

```json
{ "versao": 1, "id": 12, "situacao": "no_ar", "iniciadaEm": "...", "terminadaEm": "...",
  "commit": "a1b2c3d", "log": "últimas 200 linhas" }
```

   `situacao`: `no_ar` ou `falhou`. O `site/` não é gerado pelo builder (só muda com o `publicar.sh`).
   Antes do `rsync`, o builder confere se a pasta do pedido ainda existe (o BlueJus pode tê-la apagado por
   prazo); se não existe, não publica. Se o `rsync` do `blog` passar e o do `gratis` falhar, o resultado é
   `falhou` com o `blog` já trocado (sem reversão: o próximo pedido acerta os dois).

**O BlueJus lê o resultado**

- Tarefa `blog:conferir-publicacoes` na `AgendaDeTarefas`, a cada minuto: para cada `PublicacaoDosBlogs` `na_fila`
  ou `gerando`, `iniciado` presente vira `gerando`; `resultado.json` presente atualiza situação, commit, log e
  `terminadaEm` e apaga a pasta do pedido. Pedido sem resultado 30 min depois de `iniciado`, ou 30 min depois
  de pedido sem o builder ter começado, vira `falhou` e a pasta é apagada (o builder não publica depois um
  pedido já dado como falho). Geração que falha na rodada faz o comando sair com erro, e o aviso de falha do
  agendador avisa o Master.
- Duas gerações ao mesmo tempo: Publicar só reaproveita a `PublicacaoDosBlogs` `na_fila` cujo pacote ainda não
  foi montado; com o pacote já montado, cria outra, para a mudança nova não ficar para depois.
- Falha ao montar o pacote (imagem que sumiu do armazenamento, por exemplo): o pedido vira `falhou` com o motivo
  no log, sem nova tentativa do Messenger.

**O repositório dos sites precisa**

- `blog/src/content.config.ts` e `blog/src/lib/diretivas.mjs` (o `::leia-tambem`) honrarem `ARTIGOS`, como o
  `blog-gratis` já faz no `content.config.ts`; o `diretivas.mjs` do `blog-gratis` também.
- O zod dos dois blogs aceitar `canonical` opcional (URL que começa com `https://bluejus.com.br/blog/`), e o
  `Base.astro` usar esse valor no `<link rel="canonical">` e no `og:url` quando vier.
- `npm test` passar sem `ARTIGOS` (com os artigos de teste do próprio repositório).
- `imagem: ./imagens/{id}.{ext}` relativo ao `.md` (já é o formato do zod).

**Quem escreve em `sites-publicados` (dono dos arquivos)**

- Depois que a P4 for ao ar, `blog/` e `gratis/` são **só do builder** (uid 33). O `scripts/publicar.sh` dos sites
  passa a publicar **só o `site/`**: se ele continuasse enviando os blogs, o `rsync -a` devolveria os arquivos ao
  dono da máquina do Samuel, o builder perderia a escrita, e o `--delete` trocaria os artigos do banco pelos do
  repositório. Mudança no repositório dos sites, junto com a P4 (antecipa o item do `publicar.sh` da P5).
- Mudança de **código** dos blogs (layout, componentes) vai ao ar pedindo uma geração ao builder: comando
  `bin/console blog:gerar-de-novo` no BlueJus (cria uma `PublicacaoDosBlogs` com motivo `manual`). O
  `publicar.sh` mostra esse lembrete no fim quando houve mudança em `blog/` ou `blog-gratis/`.

### Opção B: GitHub Action disparada pelo BlueJus

- O BlueJus grava os artigos no repositório (API do GitHub: commit em `main` ou PR automático) e dispara um
  `repository_dispatch`; a Action faz o build e envia por `rsync` via SSH para a VPS.
- Prós: histórico dos artigos no git, build fora da VPS. Contras: dois segredos novos e fortes (token do
  GitHub com escrita no repositório guardado no BlueJus; chave SSH com escrita na VPS guardada no GitHub),
  porta 22 aberta para os IPs do GitHub, publicação depende do GitHub estar no ar e da fila de runners
  (2 a 5 min), e um commit por artigo no repositório de código.

### Opção C: Node dentro da imagem do php

- O worker roda `npm run build` direto. Contras: a imagem de produção do sistema ganha Node e ~300 MB, o
  build compete com as requisições dos escritórios no mesmo contêiner, e o php passaria a ter escrita em
  `sites-publicados`. Não recomendada.

**Recomendação: A.** É a que mantém o caminho mais curto e os segredos dentro do servidor. O
`scripts/publicar.sh` continua existindo para mudanças de **código** do site (ele publica o `site/` e,
para os blogs, passa a pedir ao builder em vez de gerar com os artigos do repositório, fatia P5).

## 7. Agendamento (Symfony Scheduler)

- Tarefa nova na `AgendaDeTarefas`: `blog:publicar-agendados`, cron `*/10 * * * *` em Brasília.
- O comando chama `PublicarAgendadosUseCase`: artigos `agendado` com `publicarEm <= agora` viram `publicado`
  (`publicadoEm = publicarEm`) e, se houver ao menos um, criam **uma** `PublicacaoDosBlogs` `agendado`.
  Nada a fazer = nada acontece (sem build vazio).
- Atraso máximo: 10 min + o tempo do build. A tela diz "Agendado para 05/11/2026 09:00 (vai ao ar até
  09:15)".
- Falha do build: o artigo fica `publicado` no banco, a `PublicacaoDosBlogs` fica `falhou`, e vai um aviso
  ao Master na Central de Notificações (`LeitorDeFalhasAgendadas` já existe para isso). O próximo Publicar
  ou a próxima rodada tenta de novo.

## 8. Fatias de PR

| # | PR | Conteúdo | Migration |
|---|---|---|---|
| P1 | `blog-artigos-modelo` | entidades, enums, repositórios, `HtmlDoArtigoParaMarkdown` + sanitizador, exportador para `.md` (com os testes contra o zod copiado em PHP) | sim (1) |
| P2 | `blog-artigos-telas` | seção "Artigos dos blogs" no painel, página do editor com TinyMCE e blocos, SEO com contadores, salvar rascunho; importação dos artigos atuais do repositório | não |
| P3 | `blog-artigos-imagens` | categoria `BLOG_IMAGEM`, `ChavesDoBlog`, upload do TinyMCE e capa, entrega, limpeza | não |
| P4 | `blog-builder` (este repo) + `sites-gerar-com-pacote` (sites-bluejus) | serviço `blog_builder` no compose, laço do builder, pacote do worker, `PublicacaoDosBlogs` e a faixa da tela; no repositório dos sites, `npm run build` aceita a pasta de artigos de fora (`ARTIGOS`, como o `blog-gratis` já faz nos testes) | sim (1) |
| P5 | `blog-agendamento` | Agendar, `blog:publicar-agendados` na `AgendaDeTarefas`, aviso de falha; `publicar.sh` deixa de levar artigos do repositório | não |
| P6 | `blog-previa` | prévia real pelo builder (opcional, depois do uso) | não |
| P7 | `blog-marcadores` | marcadores nos artigos (seção 11): coluna, campo com sugestões no editor, validação e frontmatter; vai junto com a P2 ou logo depois dela | sim (1) |

Ordem: P1 → P2 → P3 em sequência (mesmo domínio); P4 pode correr ao lado de P2/P3 depois do P1; P5 por
último. Uma migration por vez (P1, P4 e P7). Cada fatia tem unit dos UseCases e functional dos controllers.

## 9. Decisões para o Samuel (com a recomendação)

| # | Decisão | Recomendação |
|---|---|---|
| D1 | As telas seguem o padrão do Master ou pedimos um desenho ao Farlei no Claude Design? | **Padrão do Master agora**; desenho só se o Farlei quiser mudar depois |
| D2 | Onde mora o artigo: banco do BlueJus ou arquivos no repositório? | **Banco do BlueJus** (o repositório fica só com o código) |
| D3 | Como publica: builder na VPS (A), GitHub Action (B) ou Node no php (C)? | **A, builder na VPS** |
| D4 | Editor: TinyMCE (já temos) ou TipTap (novo)? | **TinyMCE** com a barra restrita e o menu Blocos BlueJus |
| D5 | "Os dois blogs" publica o mesmo texto nos dois endereços? O Google vê conteúdo duplicado. | **Sim, com `canonical` apontando para o Blog BlueJus**; o grátis mostra o mesmo artigo (mudança pequena no repositório dos sites, na P4) |
| D6 | Categorias e autores: continuam no código dos sites ou passam a ser cadastráveis no painel? | **Continuam no código** (12 fixas, 1 autor); o BlueJus copia a lista. Cadastro no painel só se o Farlei pedir |
| D7 | Salvar um artigo publicado muda o site na hora? | **Não**: guarda e mostra "Alterações não publicadas" até Publicar de novo |
| D8 | Prévia real (Astro) já no começo ou prévia aproximada? | **Aproximada no começo** (P2); real na P6 |
| D9 | Precisão do agendamento | **10 minutos** (tarefa a cada 10 min) |

## 10. O que o Samuel faz à mão, uma única vez

Todos os comandos vão em bloco "Execute manualmente" na fatia P4, com o passo a passo:

1. Na VPS, gerar a chave do builder: `ssh-keygen -t ed25519 -N '' -f /opt/jusprime/secrets/sites_deploy_key`.
2. No GitHub, em `sfbdata/sites-bluejus` → **Settings** → **Deploy keys** → **Add deploy key**: colar o
   `.pub`, título "builder da VPS", **sem** marcar "Allow write access".
3. Conferir que `/opt/jusprime/sites-publicados/{site,blog,gratis}` existem (já existem desde o PR #580) e dar
   ao builder (uid 33) a escrita nos blogs e a leitura da chave:
   `chown -R 33:33 /opt/jusprime/sites-publicados/blog /opt/jusprime/sites-publicados/gratis` e
   `chown 33:33 /opt/jusprime/secrets/sites_deploy_key && chmod 400 /opt/jusprime/secrets/sites_deploy_key`.
   Fazer **depois** do merge do PR dos sites que tira os blogs do `publicar.sh` (seção 6.1), senão o próximo
   `publicar.sh` desfaz o `chown`.
   Os passos 1 e 3 vêm **antes** do deploy da P4: se a chave não existir no `up -d`, o Docker cria uma pasta com
   o nome dela e todo pedido falha no `git fetch` (conserto: `rmdir` da pasta, gerar a chave, o `chown`/`chmod`
   e `docker compose -f docker-compose.prod.yml up -d --force-recreate blog_builder`).
4. Depois do merge da P4, rodar o deploy normal: ele sobe o contêiner `blog_builder` (o `up -d` cria o
   serviço novo).
5. Rodar uma vez a importação dos artigos atuais (P2), com a raiz do repositório dos sites copiada para dentro do
   contêiner: `bin/console blog:importar-do-repositorio <pasta-dos-sites> --simular` para conferir, depois sem `--simular`.

Nada disso precisa ser repetido depois: publicar e agendar passam a ser cliques do Farlei.

## 11. Marcadores (pedido do Samuel, 03/11)

Etiquetas livres no artigo, além da categoria fixa: "INSS", "Prazo em dobro".

**Contrato do campo** (o mesmo para o BlueJus e para o repositório dos sites):

- `marcadores`: opcional, lista de **até 8**, cada um com **até 40 caracteres** de texto livre, sem espaço
  nas pontas e sem espaço duplo no meio.
- O **slug** de cada marcador sai do texto sem acento, em minúsculas, com hífen no lugar do resto
  (`Prazo em dobro` → `prazo-em-dobro`), na mesma regra do slug do artigo. Marcador cujo slug fica vazio é recusado.
- Dois marcadores do mesmo artigo com o mesmo slug (`INSS` e `inss`) contam como um: fica a primeira grafia.
- No Markdown exportado, sai no frontmatter, depois de `categoria`, como lista do YAML:
  `marcadores: ["INSS", "Prazo em dobro"]`. Sem marcadores, a chave não sai.

**No BlueJus:**

- Modelo: coluna `marcadores` (`jsonb`, lista de textos, padrão `[]`) em `artigo_blog`, com migration própria
  (número pedido ao Coordenador). A grafia escolhida é guardada; o slug é calculado, não gravado.
- Editor (P2): campo de marcadores que **sugere os já usados** nos outros artigos, pela busca no
  `ArtigoDoBlogRepository::marcadoresUsados(busca)` (`jsonb_array_elements_text`, agrupado por slug,
  mais usados primeiro, até 10). Escolher uma sugestão reaproveita a grafia que já existe, para "INSS" não
  virar também "Inss".
- `ValidadorDoArtigo` e o Input conferem os limites acima; a mensagem diz qual marcador passou do limite.
- Exportador: a chave `marcadores` no frontmatter, na ordem do editor.

**Nos sites** (sessão dos sites, fora deste repositório): o zod aceita `marcadores` opcional com os mesmos
limites; página `/blog/marcador/<slug>` com os artigos do marcador, com `noindex`. O repositório dos sites
precisa aceitar a chave **antes** de o BlueJus exportá-la: confira isso antes do merge da P7.

