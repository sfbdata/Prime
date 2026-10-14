# Changelog

Todas as mudanças relevantes do BlueJus ficam registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o
[Versionamento Semântico](https://semver.org/lang/pt-BR/). A política de versões, os critérios de
cada nível e o procedimento de release estão em [docs/versionamento.md](docs/versionamento.md).

## [Não lançado]

Tudo o que entrou no master desde a `v1.0.0`, ainda sem versão. São cerca de 2.000 commits feitos
antes de existir este registro: a lista abaixo cobre o que dá para comprovar pelo histórico (o
commit de origem vem entre parênteses), não cada correção. A próxima versão proposta é a **2.0.0**,
porque houve remoção de funcionalidade e de endereços (ver "Removido") — a justificativa está em
`docs/versionamento.md`.

### Adicionado

- Expediente: listagem de pastas e processos (`c172e047`).
- Kanban com quadros, cartões, checklists e marcadores (`daf8b2a8`).
- Dashboard com indicadores agregados do escritório (`f8dcc817`).
- Termos de Uso com aceite versionado exigido antes do acesso (`e2372ce9`).
- Cadastro público de novos escritórios (`22fcfc21`).
- Sincronização de pastas com o Google Drive (`7103dfa8`).
- Captação de publicações do DJEN por escritório (`b044c0c1`).
- Módulo de Cobranças (`475aeeb9`).
- Atualização monetária por índices do Banco Central na simulação de acordo (`35475d00`).
- Política de Privacidade como página própria e em PDF (`69e5da9c`).
- Fundação da BlueJus IA, ainda sem provedor de linguagem ligado (`f05bf1f8`).
- Agente de Pastas, Fase 1: ferramentas de leitura em `/api/agente/*` (buscar e contar pastas com
  os filtros da Lista do Expediente, listar documentos de uma pasta, vocabulário do escritório).
- Portões de CI em todo pull request: guarda de segredos (#5) e lints com testes unitários e de
  arquitetura (#9).

### Alterado

- Identidade global: uma conta por pessoa e login único por e-mail, com o escritório escolhido
  depois do login (refatoração em etapas, de `1e6ffb67` a `55bfeed5`; seletor de escritórios no
  cabeçalho em `576c093b`).
- Tarefas vinculadas a pastas, com o ciclo Pendente → Revisão → Concluída (`84e69c8a`).
- E-mails do sistema com identidade institucional BlueJus (#2).
- Pasta e Dashboard no padrão visual PJe, Trilha A (`4d1d054a` a `6de3b60d`).
- Fidelidade das telas ao desenho aprovado no Claude Designer, lotes 1 e 2 do Engineering Loop
  (#6, #7).

### Removido

- Módulo de Pré-cadastro, com suas telas e permissão (`e790cf87`).
- Página avulsa de Clientes e o seu item de menu (`c264fa06`).
- Telas e endereços do fluxo antigo de Tarefas: nova, admin, encerrar, enviar para revisão e enviar
  pendência (`84e69c8a`).
- Upload legado de documentos da Pasta (`b575428d`).

### Segurança

- Credencial de e-mail retirada do repositório e guarda de segredos no commit e no CI (#5).
- Requisições web não conseguem mais trocar o ambiente da aplicação pela query string
  (CVE-2026-47767; #8).

## [1.0.0] - 2026-03-31

Primeira versão marcada no repositório (tag `v1.0.0`, commit `4940cc87`). Não há registro
detalhado do que ela continha.

[Não lançado]: https://github.com/sfbdata/Prime/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sfbdata/Prime/releases/tag/v1.0.0
