# E12 · Lista do Expediente integral contra o 1.1 online (16/10/2026)

> **Para quem:** o dono e quem revisar ou continuar esta entrega da frente `fidelidade-designer`.
> **Risco:** MÉDIO (migration + permissão nova "só administrador do escritório"). Por isso esta spec.
> **Fonte:** `03 - EXPEDIENTE Lista 1.1.dc.html` lido pelo MCP `claude_design` em 16/10 (680 linhas,
> etag `1792155187406274`). É **mais novo** que a cópia local de 13/10 (544 linhas) contra a qual a E8d
> auditou. Referências `D:nnn` abaixo são linhas do arquivo de 16/10.

## 1. Pedido do dono (16/10)

"Implementar e atualizar a expediente_index: aplicar todas as funcionalidades, efeitos visuais, comandos
com o botão direito, ajustes e comandos visíveis e não visíveis, respeitando o projeto na íntegra do Claude
Designer." Respostas às perguntas de escopo:

- Blocos A (só tela), B (backend sem migration), C (migration e decisão) e D (efeitos de IA): **todos**,
  "aplicar forma idêntica do projeto".
- Frente: `fidelidade-designer`, em cima da E8 a E8d.
- Protocolar: **mostrar desativado** (sem contagem, dica "em breve").

Isso **substitui** as decisões anteriores que tiravam itens do desenho: "menus só com ações que existem",
"2 modos de visualização", "rótulo do sistema na prioridade", "cliente opcional na Nova Pasta",
"só Ativo/Arquivado". Continuam valendo (não contrariam o desenho, ou são regra global): hierarquia e vários
marcadores por pasta (o desenho já tem submarcadores), lápide da pasta excluída, sem travessão em texto
visível, foco visível, multi-tenant.

## 2. Inventário do desenho e destino de cada item

Legenda do destino: **T** só tela · **B** backend sem migration · **M** com migration · **X** sem lastro.

### 2.1 Trilho (D:74-95)
| Item | Destino |
|---|---|
| Largura 290 aberto / 64 recolhido, fundo #e9eff2, borda #d3dde4 | T |
| "Todas as pastas" 42px com contagem na dica e botão ↻ (zera filtros, efeito de busca, aviso) | T |
| Seção EXPEDIENTES com cadeado "filtros do sistema" e 6 filtros com contagem; botão direito abre nota | T + B |
| Cabeçalho MARCADORES com ⋮ (Novo marcador, Ver todas as pastas, Recolher/Expandir painel) | T |
| Marcador: seta de submarcadores, pasta cheia na cor, ⋮ no hover, contagem | T |
| Arrastar e soltar para reordenar (mesmo nível; submarcador só entre irmãos) | T + B |
| Botão direito no marcador: Ver pastas, Novo marcador, Novo submarcador, Mover para cima, Mover para baixo, Editar, Duplicar, Excluir | T + B |

### 2.2 Filtros EXPEDIENTES (D:571) e lastro medido
| Filtro | Regra implementada | Lastro |
|---|---|---|
| Respondidos nos últimos 10 dias | pasta com mensagem, observação (detalhes ou financeira), meta criada ou concluída, ou resposta de meta nos últimos 10 dias | existe |
| Prazo findou nos últimos 10 dias sem resposta | meta não concluída com prazo entre hoje−10 e ontem, sem atividade na pasta depois do prazo | só metas (não há prazo manual nem prazo do Push gravado) |
| Audiência | **desativado** como o Protocolar | não existe vínculo de audiência com pasta |
| Expediente com prazo | meta não concluída com prazo hoje ou depois | só metas |
| Metas não respondidas | meta não concluída sem nenhuma resposta | existe |
| Protocolar | **desativado** (decisão do dono) | não existe fase de andamento |

As dicas dizem o que a regra realmente conta (ex.: "prazo das metas"), não o texto do protótipo que cita
prazo manual e Push. Audiência segue o mesmo tratamento que o dono escolheu para Protocolar.

### 2.3 Barra do acervo e filtros (D:119-182)
| Item | Destino |
|---|---|
| Título clicável que volta ao acervo zerado | T |
| Nova Pasta (botão) | T |
| Situação em menu próprio: Todas, Ativo, Suspenso, Cancelado, Arquivado, com ponto pulsante | T + B |
| Busca 34px | já ✓ |
| Responsável em menu próprio com busca e avatar | T (o `<select>` fica escondido como fonte do formulário) |
| Prioridade em menu próprio: Todas, Normal, **Alta**, Urgente | T (rótulo do desenho; valor `prioridade` do banco) |
| Período, limpar (ícone) | já ✓ / T |
| Visualizar: Detalhes, Detalhes compactos, Lista, Cartões, Cartões grandes; Pastas por página 25/50/100; Painel de marcadores | T + B |
| Paginação e resumo | já ✓ |
| Botão de colunas (clique e botão direito): liga/desliga cada coluna (NUP fixo), Mostrar todas, Só as essenciais, Restaurar padrão, Exportar colunas visíveis (CSV) | T + B (CSV de todas as pastas filtradas, no servidor) |

### 2.4 Tabela (D:190-234)
| Item | Destino |
|---|---|
| Colunas NUP · Cliente · Réu · Ação · Prioridade · Responsável · Metas · Marcadores · Situação | T + B (Réu) |
| Réu: primeira parte de polo passivo/réu dos processos da pasta, "e outros" se houver mais; "Não informado" em cinza | B |
| Selo "Nx" ao lado do cliente: pastas do mesmo cliente principal | B |
| Cadeado no NUP da pasta trancada, com dica de quem trancou e quando | M |
| Destaque de linha em 6 cores, por usuário | M |
| Hover azul, menus de responsável e marcador | já ✓ |
| Situação Suspenso (#8a5a12) e Cancelado (#a3232b) | B |

### 2.5 Menu de botão direito da linha (D:346-367)
Abrir pasta · Abrir em nova aba · Editar nome rápido (F2) · Alterar responsável · Alterar marcador ·
Copiar número · Copiar nome · Trancar/Destrancar (só administrador; rótulo "só primário" para os demais) ·
Destacar linha (6 cores + sem destaque). Pasta trancada aberta por quem não é administrador: janela
"Pasta N protegida" com Entendi / Pedir acesso.

### 2.6 Modais
| Item | Destino |
|---|---|
| Nova Pasta 520px, arrastável pelo cabeçalho, aviso "PASTA {próximo número}", cliente obrigatório com sugestões e "já cadastrado em N pasta(s)", ação liberada só depois do cliente | T + B (próximo número e sugestões) |
| Novo/Editar marcador 420px: nome, descrição opcional, "Dentro de (submarcador)", 10 cores do desenho, prévia, erro "já existe" | T + M (descrição) + B (trocar pai, nome único) |
| Excluir marcador | já ✓ (E8b) |
| Sigilo | M |

### 2.7 Efeitos (D:22-50, 102-117, 184-189)
Pasta do título que fecha e abre (já ✓), ponto pulsante (já ✓), efeito "Só um instante, estamos buscando
em todo o sistema…" ao trocar de filtro do trilho (1,3 s), sobreposição "BlueJus I.A." disparada pelo
evento `bj-ai-fx` (casca: nada no BlueJus dispara o evento ainda), dicas no estilo do desenho (fundo #e6f2fa,
só quando o texto está cortado ou não está visível). Todos respeitam "reduzir movimento".

## 3. Decisões técnicas (onde o desenho é omisso)

1. **"Usuário primário" = administrador do escritório** (`TenantRole.isSystem`, o mesmo bypass do
   `PermissionChecker`). Não existe dono de escritório no modelo; isto é o mais próximo e já é a
   autoridade máxima do escritório.
2. **Trancar** grava `pasta.trancada_em` e `pasta.trancada_por_id`. A proteção é a do desenho: aviso na
   Lista ("o acesso não é recomendado"), **não** bloqueio no servidor da `pasta_show`. Bloquear de fato
   mexeria em autorização de outra tela e fica para decisão do dono.
3. **Pedir acesso** cria notificação real para cada administrador do escritório (tipo novo
   `pasta_pedido_acesso`). O aviso diz "Pedido de acesso enviado ao administrador do escritório", não "pelo
   Chat I.A.", porque o Chat I.A. não existe no sistema.
4. **Destaque** é por usuário (tabela `pasta_destaque`: usuário, pasta, tenant, cor), como o desenho guarda
   por navegador; não aparece para os colegas.
5. **Suspenso e Cancelado** entram como valores de `pasta.situacao` (coluna já é texto de 20). Consultas
   que hoje pedem "ativa" continuam pedindo só `ativo`. Esta entrega cobre filtro e exibição na Lista;
   **como marcar** uma pasta como suspensa ou cancelada é da tela da Pasta (desenho 02), fora desta entrega.
6. **Editar nome rápido** altera `pasta.nome_cliente` (o identificador da pasta, ver memória
   `pasta.nome_cliente é identificador`), recusado em pasta excluída; não reativa pasta arquivada.
7. **CSV** é gerado no servidor com os mesmos filtros e a mesma ordem, todas as páginas, separador `;`,
   BOM UTF-8, colunas visíveis (Metas fora, como o desenho).
8. **Preferências de tela** (modo, colunas, por página, trilho, submarcadores abertos) ficam no
   `localStorage`, como o desenho; nada disso é dado de negócio.
9. **Próximo número** da Nova Pasta é só informativo (o servidor continua gerando o número ao salvar).

## 4. Migration

Uma só: `pasta.trancada_em` (timestamp nulo), `pasta.trancada_por_id` (FK user nula, `ON DELETE SET NULL`),
`marcador.descricao` (varchar 255 nulo), tabela `pasta_destaque` (id, tenant_id, usuario_id, pasta_id, cor
varchar 20, único usuário+pasta, `ON DELETE CASCADE`).

## 5. Testes

Unit dos UseCases novos (reordenar, duplicar, renomear, trancar, destacar, pedir acesso, editar marcador
com pai e descrição); functional dos endpoints (isolamento entre escritórios em cada um, CSRF, permissão de
administrador no trancar); repositório dos filtros EXPEDIENTES e do Réu/Nx; testes de arranjo da tela com
combinador de filho direto. Suíte completa no fim, rodada sozinha.

## 6. Fora desta entrega

Bloqueio real de acesso à pasta trancada no servidor; marcar Suspenso/Cancelado na Pasta; prazo manual,
prazo do Push e audiência (sem dado); Protocolar (sem fase); Chat I.A. e Assistente de pastas.

## 7. Conferência item a item (16/10, depois de `02fb00fd`)

✓ igual ao desenho · ≈ diferente, com o motivo. Conferido no código e nos testes de arranjo; **a aparência
ainda precisa do smoke do dono** (a suíte não vê cor, fonte nem posição fina).

| # | Item do desenho | Estado |
|---|---|---|
| 1 | Trilho 290px, recolhido 64px | ✓ |
| 2 | "Todas as pastas" 42px com ↻ que gira, efeito de busca e aviso "Lista atualizada" | ✓ |
| 3 | EXPEDIENTES com cadeado e 6 filtros com contagem; ativo azul; "Prazo findou" vermelho com pasta | ✓ |
| 4 | Audiência | ✓ E12b: conta as audiências registradas em "Próximos prazos" da pasta (§9) |
| 5 | Protocolar | ✓ E12b: conta pela fase Protocolo do andamento da pasta (§9) |
| 6 | Botão direito no filtro do sistema abre a nota | ✓ (texto conta o que a regra realmente faz) |
| 7 | ⋮ de MARCADORES: Novo, Ver todas, Recolher/Expandir; recolher no meio do cabeçalho | ✓ |
| 8 | Marcador: seta dos submarcadores, pasta na cor, ⋮ no hover, contagem do pai somando os filhos | ✓ (pastas distintas: a pasta pode ter vários marcadores) |
| 9 | Arrastar e soltar com a linha azul; Mover para cima/baixo | ✓ |
| 10 | Menu do marcador (Ver, Novo, Novo submarcador, Cima, Baixo, Editar, Duplicar, Excluir) | ✓ |
| 11 | Modal do marcador: nome, descrição, "Dentro de", 10 cores, prévia, "Já existe um marcador com esse nome" | ✓ (marcador antigo pastel abre com a cor equivalente do desenho) |
| 12 | Excluir com a janela de alerta | ✓ (texto "ficam sem este marcador": a pasta pode ter outros) |
| 13 | Título da barra = filtro, marcador ou "Acervo geral"; clique volta ao início | ✓ |
| 14 | Nova Pasta 520px, arrasta, "PASTA {próximo}", cliente obrigatório, sugestões | ✓ |
| 15 | "A nova pasta será vinculada ao mesmo cadastro" | ≈ omitido: o sistema só grava o nome, a frase seria falsa |
| 16 | Situação em menu com 5 opções e ponto pulsante | ✓ (marcar Suspenso/Cancelado é da tela da Pasta, fora) |
| 17 | Responsável em menu com busca e avatar; Prioridade em menu (Normal, Alta, Urgente) | ✓ (avatar só com iniciais; o filtro não tem a foto) |
| 18 | Limpar em ícone, só com filtro | ✓ (chip só para "Criado por", que vem do Dashboard e não tem campo) |
| 19 | Visualizar: 5 modos, Pastas por página 25/50/100, Painel de marcadores | ✓ |
| 20 | Colunas (clique e botão direito), Mostrar todas, Só as essenciais, Restaurar, CSV | ✓ (CSV no servidor, todas as páginas) |
| 21 | Cabeçalho sem ícones abaixo de 1180px | ✓ |
| 22 | Tabela com Réu, selo Nx, cadeado com quem trancou | ✓ |
| 23 | Metas | ✓ número de metas abertas, como o desenho (regra do dono de 16/10: "tudo é sempre como está no desenho"); sem meta aberta a célula fica vazia, sem o "–" (regra sem travessão) |
| 24 | Chip do marcador e menu MARCADOR com "Remover marcador" | ✓ (escolher liga/desliga, porque a pasta pode ter vários) |
| 25 | Situação com 4 rótulos e cores | ✓ |
| 26 | Hover azul e destaque em 6 cores | ✓ |
| 27 | Detalhes compactos, Lista, Cartões, Cartões grandes | ✓ |
| 28 | Botão direito da linha completo, F2 no próprio menu, Trancar "só primário", Destacar | ✓ |
| 29 | Janela "Pasta N protegida" e Pedir acesso | ✓ (vai como notificação aos administradores; texto diz isso, não "Chat I.A.") |
| 30 | Dicas azul-claras só quando o texto está cortado | ✓ |
| 31 | Sobreposição BlueJus I.A. | ≈ pronta e ligada ao evento `bj-ai-fx`, mas nada no sistema dispara (não há Assistente) |

Só do BlueJus, mantidos: estrela de favorito (tabela e cartão), "+N" de vários marcadores, duplo clique na situação
(ativo/arquivado), lápide da pasta excluída, chip "Criado por".

## 8. Para o smoke do dono (o que olhar na tela)

Claro e escuro, 1440, 1280, 1024 e celular: trilho (aberto, recolhido, arrastar, menus, modal do marcador),
filtros do sistema com contagem, menus de Situação/Responsável/Prioridade, Visualizar nos 5 modos e por página,
colunas e CSV, botão direito da linha (F2, trancar como administrador e como colaborador, destacar), janela de
sigilo e Pedir acesso (o sino do administrador), Nova Pasta. O teste visual de Playwright da E8d
(`e2e/tests/expediente-lista-visual.spec.js`) mirava o trilho antigo e precisa ser revisto junto com o smoke.

## 9. E12b (16/10): Audiência e Protocolar de verdade

Pedido do dono: "faça e deixe tudo pronto". No desenho da Lista os dois filtros leem o que a tela da Pasta grava
(desenho 02, `bj-pz-manual-v1` e `bj-pasta-fases`). Então entraram, na Pasta, as duas funções do desenho de onde
eles vêm. Feito nesta sessão, com a outra sessão (auditoria da pasta_show, plano P1-4) fora desses itens.

**Prazos e audiências** (`13aa6645`): entidade `PastaPrazo`; painel "Próximos prazos" junta prazos manuais (origem
Manual) e metas abertas com prazo (origem Meta), mostra 8 e conta todos; "+" abre "Novo prazo ou audiência"
(Prazo/Audiência, descrição, data, hora opcional, responsável em texto livre); no ⋮ dos manuais, Marcar como
cumprido, Editar e Excluir (sem confirmação, como o desenho); textos do selo do desenho ("Hoje", "Faltam N dias",
"Vencido há N dias"); cartão do sistema no Registro ("Prazo: Audiência agendada: …"). Filtros da Lista: Audiência,
Com prazo, Prazo findou e Respondidos passam a contar os prazos manuais.

**Andamento da pasta** (`66f1a1cd`): entidade `PastaAndamento` (uma linha por pasta e via); trilha de 10 fases sob o
nome (judicial ou administrativa); fase estimada quando não há andamento; clique pergunta na janela da tela com os
textos do desenho e grava (avançar, voltar, pular em vermelho, finalizar, reativar); faixa "Avançar para X?" com
Avançar e Ignorar; cartão "Andamento: …" no Registro; contrato: alcançar Contrato marca o contrato como regular,
voltar o reabre, e marcar regular no Financeiro leva a pasta até Contrato. "Administrativo sem processo" já existia
(campo `administrativa`): agora troca a trilha e registra no Registro. Filtro Protocolar: pasta ativa, não
administrativa, que não passou por Protocolo (com processo vinculado e sem andamento, conta como já protocolada).

**Fora, sem lastro hoje:** prazos lidos do Push e das datas escritas nos registros (o desenho tem um leitor de
datas com dias úteis; o servidor não tem); sugestão de fase por "documentos novos anexados"; "Encaminhar via Chat
I.A." e "Alertar" no prazo manual (o responsável é texto livre, não colaborador).

## 10. E12c: datas escritas nos registros viram prazos (16/10/2026)

Pedido do dono: escrever "Prazo 16/10/2026" (ou "audiência ... 20/11/2026") num registro da pasta faz o sistema
reconhecer o prazo como o desenho 1.2.3 (`pzLista` + `Component.lerDatas`), sem desfazer os outros caminhos (prazo
manual do "+", metas).

- `App\Pasta\Service\LeitorDeDatas`: porte do `lerDatas`/`diaUtil`/`somarDias` (palavras de tipo, datas numéricas e
  por extenso, "prazo de N dias úteis" estimado, amanhã/hoje, contexto passado, remarcação, hora). Duas diferenças
  declaradas no docblock: parágrafo separa frase; número CNJ e ano fora de 1990 a 2100 não viram data.
- `pasta_prazo` ganha `origem` (manual | registro), `registro_ref`, `estimado` (`Version20261016220000`, só colunas
  com default).
- `PrazosDosRegistros::sincronizar(Pasta)` lê Registro dos expedientes (sem cartões do sistema), Detalhes e
  Financeiro, tira repetidos por tipo+data na pasta (como o desenho) e acerta a tabela. `PrazosDosRegistrosListener`
  relê a pasta a cada registro criado, editado ou excluído.
- Trilho: origem "Registro dos expedientes" / "Detalhes" / "Financeiro", com " (estimado: conferir)" quando contada;
  sem cumprir/editar/excluir (o desenho só dá essas ações ao manual). Salvar/Cumprir recusam o lido no servidor.
- Lista: os filtros Audiência, Expediente com prazo e Prazo findou já leem `pasta_prazo`, então passam a ver os lidos.
- Acervo anterior: `app:pastas:ler-prazos-dos-registros [--dry-run] [--pasta=ID]`, idempotente. No dev (saas_ux):
  138 pastas lidas, 31 prazos criados. Em produção é do dono, depois da migration.
- Como o desenho, data lida que já passou aparece como "Vencido há N dias" (registros antigos trazem datas antigas).
- Testes: `LeitorDeDatasTest` (18), `PrazosDosRegistrosTest` (6, prova por mutação: sem a releitura os 6 falham).

## 11. E12d: Situação da pasta com as 4 opções do desenho (16/10/2026)

Pedido do dono (capturas do 1.2.3): pílula Ativo, Suspenso, Cancelado, Arquivado "com toda a programação". Plano
aprovado: Situação, depois ajustes da linha do tempo, depois Cadastro em 4 fatias. Decisões: igual ao desenho
(Suspenso e Cancelado não travam nem saem das listas); réu cadastrado na pasta manda na coluna Réu; CEP pelo ViaCEP.

- `DefinirSituacaoDaPastaUseCase`: tenant + EDIT, situação dentro de `Pasta::SITUACOES`, lápide recusada; mesma
  situação não grava nada. Cartão do sistema "Situação da pasta: alterada de "X" para "Y"." (rótulo `situacao`).
  Arquivar chama `AndamentoDaPasta::finalizarPorArquivamento` (vai a Finalizada sem pular, "Motivo: pasta
  arquivada."); desarquivar não reverte a fase (como o desenho).
- Rota `pasta_alternar_situacao`: com `situacao` (pílula) define; sem ela (menu ⋮) alterna arquivada para Ativo e
  qualquer outra para Arquivado.
- Fechados os caminhos que devolviam Suspenso/Cancelado a Ativo: toggle antigo da rota; modal "Editar dados" (4
  opções com a atual marcada; `EditarPastaUseCase` aceita as 4); selo da tela Pastas (`_tabela`, `_card`) fixo
  para Suspenso/Cancelado, como a Lista do Expediente.
- Cabeçalho: pílula e menu das 4 situações com as cores do desenho (claro e escuro); carimbos permanentes
  ARQUIVADO, SUSPENSO, CANCELADO; ATIVO passageiro 1,4 s depois de abrir a pasta ativa (desenho
  `ativoSeloMostrar`; antes só na reativação). Trocar a situação recarrega a tela (cartão e fase aparecem).
- Rótulo real na impressão, no contexto da IA e na linha do tempo do Histórico (antes suspensa aparecia como
  arquivada).
- Testes: `PastaSituacaoControllerTest` (7; mutação no toggle e no arquivar→Finalizada derrubam um cada),
  `EditarPastaUseCaseTest` (+1); três testes antigos que registravam "só 2 situações" e "ATIVO só na troca"
  atualizados para a regra do desenho.
- Revisão (interrompida a meio, achados medidos aplicados): a tela manda sempre o destino (menu ⋮ e duplo clique
  da Lista), então tela desatualizada não inverte a situação; "Editar dados" passa a situação pelo mesmo caso de uso
  (cartão e arquivar→Finalizada) e campo ausente não mexe; escritório nulo dá 404. `_tabela`/`_card` da antiga
  tela Pastas não são mais desenhados por rota nenhuma (a tela virou a Lista na E12): a correção fica, sem teste
  de tela. Ficou para o dono: Push indisponível em pasta cancelada/arquivada (regra implícita do protótipo,
  PZ-CAB-48) e o carimbo ATIVO aparecer 1,4 s depois da recarga em vez de na hora.
- Testes: `PastaSituacaoControllerTest` com 10 casos (outro escritório, tela desatualizada, Editar dados).

## 12. E12e: ajustes da linha do tempo (16/10/2026)

- Sugestão "Avançar para X?": o Push lê também o `tipoDocumento` da publicação (a comunicação costuma vir como
  "Intimação"; quem diz Sentença/Decisão é o documento). `PublicacaoDjenListaItem` ganhou o campo, projetado só por
  `listarItensPorNumerosDoTenant`.
- Nova fonte do desenho: documentos vivos (fora da lixeira) na aba Documentos sugerem "Documentação" com "N
  documento(s) anexado(s) na aba Documentos" (`PastaDocumentoRepository::contarVivosDaPasta`).
- "Ignorar" passa de sessionStorage para localStorage por pasta (lembra depois de fechar o navegador). Guardar no
  servidor foi descartado: uma linha de andamento só para isso mudaria o filtro Protocolar da Lista, que decide
  pela existência da linha.
- Testes: `SugestoesDoAndamentoTest` (3; mutação em cada fonte nova derruba o seu).

## 13. E12f: Cadastro do cliente, fatia 3a (16/10/2026)

Decisões do dono: RG e Órgão emissor em dois campos lado a lado; Contato = Telefone (o `telefone_celular`),
WhatsApp (novo) e E-mail, com o fixo gravado mas fora da janela; empresa usa a mesma janela adaptada (aba "Empresa":
razão social, CNPJ, nome fantasia, inscrições, representante, CPF, RG e cargo dele).

- Migration `Version20261016234500` (renomeada de 233000, que colidia com a Central no origin/master): `numero`,
  `bairro`, `whatsapp` em cliente; `nacionalidade`, `genero` em cliente_pf; `nome_completo` de 50 para 150.
- `DigitoVerificador` (CPF/CNPJ). `PendenciasDoCadastro::porAba` com os textos e abas do desenho (Logradouro,
  Número, Bairro; PJ cobra representante e o CPF dele). `QualificacaoDoCliente` no modelo do desenho
  (nacionalidade, número, bairro, WhatsApp diferente do telefone, órgão do RG, concordância por gênero corrigida:
  masculino tira "(a)", feminino "o(a)" vira "a").
- `SalvarCadastroDoClienteUseCase`: tenant + EDIT do cliente; só o campo enviado muda; nome vazio, CPF/CNPJ inválido,
  data impossível, campo maior que a coluna e e-mail malformado são recusados (422 com a aba); CPF/CNPJ de outro
  cadastro dá 409 e só diz de quem é a quem pode ver; CEP e e-mail não viram "alteração" por formato; estado civil
  antigo fora da lista não trava. Cartão "Cadastro do cliente: {NOME}: {campos} alterado(s)." na pasta de origem.
- `ClienteCadastroController` (`cliente_cadastro`, `cliente_cadastro_salvar`, `cliente_cadastro_documento`).
  Janela `cliente/_cadastro.html.twig` + `cliente-cadastro.js` + `cliente-cadastro.css`: abas Cliente/Empresa,
  Endereço, Contato, Qualificação; pendências ao vivo com ponto laranja por aba; máscaras; aviso de CPF inválido
  e de e-mail; busca de CEP no ViaCEP (autorizada); "Este CPF já possui cadastro no sistema." com Abrir cadastro e
  Utilizar cadastro existente (vincula e torna principal pelas rotas que já existem); Salvar desligado sem
  alteração (defeito do desenho não copiado); Fechar com alteração pede segundo clique; Ctrl/Cmd+S e Esc.
  O botão Cadastro do cabeçalho abre esta janela; o botão direito no nome segue em "Detalhes do cliente".
- Impacto visível: todo cliente passa a cobrar Número e Bairro (colunas novas), então o ponto do Cadastro fica
  laranja até alguém completar; empresa passa a cobrar o representante.
- Fica para o dono: "brasileiro(a)" vem preenchido como no desenho e é gravado no primeiro Salvar da pessoa.
- Revisão read-only: 2 bloqueios (CEP só com dígitos e e-mail com maiúsculas viravam "alteração"; nacionalidade
  gravada junto) e 6 "deveria" aplicados. Testes: `ClienteCadastroControllerTest` (13; mutação na permissão e na
  normalização do CEP derrubam o seu), `DigitoVerificadorTest`, pendências e qualificação atualizados.
- Próximas fatias: 3b Documentos, 3c Réu, 3d Histórico.

## 14. E12g: Cadastro do cliente, fatia 3b, aba Documentos (16/10/2026)

- Aba Documentos entre Contato e Qualificação, com os dois cartões do desenho: "Documento de identificação" (CNH ou
  RG) e "Comprovante de residência" (conta de consumo, contrato de locação ou similar). Sem anexo: "não anexado" e
  Anexar; com anexo: "{arquivo} · anexado em dd/mm/aaaa · N KB", Visualizar, Baixar, Substituir e Excluir (o mais
  recente de cada categoria).
- Sem backend novo: usa as rotas de documento de cliente que já existiam (enviar, ver, baixar, excluir), que
  conferem EDIT do cliente e CSRF. Excluir confirma dentro do próprio cartão (sem `confirm()` nativo). Substituir
  envia o novo, relê a janela e só exclui o antigo se o novo apareceu: a rota de envio redireciona até quando recusa
  o arquivo, e sem essa conferência o antigo seria apagado sem substituto.
- Depois de cada ação a janela é trocada pela mesma, já na aba Documentos; ao fechar, a tela recarrega.
- Leitura automática: depois de anexar a identificação aparece o texto do desenho para quando o OCR não existe ("O
  serviço de leitura (OCR) não está configurado neste ambiente…"). Inteligência cadastral fica fora (sem serviço).
- `cliente_cadastro` aceita `?aba=` e `?ocr=1`. Testes: `ClienteCadastroControllerTest` (15; mutação nos botões de
  quem só vê derruba o seu). O JS de envio/substituição não tem teste automático (smoke do dono).
- 🔎 Visto e não mexido: as rotas antigas de enviar/excluir documento de cliente não conferem o escritório do cliente
  além do `PermissionChecker` (mesma família do achado da revisão da E12d).

## 15. E12h: Cadastro, fatia 3c, aba Réu (16/10/2026)

- Tabela `pasta_reu` (migration `Version20261017090000`): nome (maiúsculas), documento só com dígitos ou nulo,
  ordem. Some com a pasta (CASCADE) e entra na purga do escritório.
- `GerenciarReusDaPastaUseCase` (tenant + EDIT da pasta): adicionar, subir (o de cima vira Principal), remover com
  renumeração. Recusas: sem nome; documento de tamanho errado ("CPF com 11 ou CNPJ com 14 dígitos", texto do
  desenho); dígito errado; repetido (o desenho descartava em silêncio: aqui avisa). Limite de 50 por pasta.
- Rotas `pasta_reu_adicionar|subir|remover` (JSON com a lista inteira), CSRF `pasta_reus_{id}`.
- Aba Réu na janela do Cadastro, logo depois de Cliente, só quando aberta de uma pasta (o réu é da pasta). Texto do
  desenho, campos sem `name` (não entram no Salvar do cliente), lista com iniciais, "Principal", documento
  formatado, seta para subir e lixeira com confirmação na linha. Quem não edita a pasta só vê a lista.
- Lista do Expediente e CSV: o réu cadastrado manda ("NOME e outros"); sem cadastro, as partes do Datajud.
- Corrigido de passagem (3a): subtítulo da janela e aviso de CPF repetido mostravam só "CPF", sem o número.
- Testes: `PastaReuControllerTest` (6; mutação na preferência da Lista derruba o seu), janela com e sem pasta.
- Próxima: 3d Histórico.
- Revisão read-only (nada bloqueava), aplicado: a pasta de contexto da janela só vale para quem pode VER a pasta
  (sem isso, quem via só o cliente lia os réus); pasta excluída mostra a lista sem formulário e a recusa da lápide
  aparece com a mensagem dela; botão Adicionar trava durante o envio; teste novo prova "cadastro manda, Datajud de
  reserva" com partes reais (`testCadastroMandaDatajudDeReserva`). Mantido: "CPF com 11 ou CNPJ com 14 dígitos" é o
  texto literal do desenho. Corrida na ordem entre dois envios simultâneos fica cosmética (o próximo subir/remover
  renumera) e sem índice único.
