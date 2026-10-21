---
tipo: correcao
---
## Changelog
- Build de produção: o `.env` de mentira do `cache:warmup` no Dockerfile ganhou `MAILER_FROM` (endereço válido, a `IdentidadeDeEmail` valida) e `DJEN_BASE_URL`. Sem o `MAILER_FROM`, o warmup do Twig instanciava a `FiltroDaCampanhaExtension` e o build da v2.11.0 caía em "Environment variable not found". O CI não via porque usa o `.env` do repositório.
- Teste de arquitetura `EnvDoBuildArquiteturaTest`: toda `%env(NOME)%` sem `default:` nos YAML de `config/` (fora de `when@dev`/`when@test`) precisa estar no printf do Dockerfile.

## Para o cliente
- Nada muda na tela: é a correção que permite publicar a versão nova.
