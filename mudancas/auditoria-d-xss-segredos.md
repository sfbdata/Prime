---
tipo: correcao
---
## Changelog
- Auditoria 5.01 (XSS por DOM no Expediente): `expediente-nova-pasta-push.js` não aceita mais a URL do POST pelo hash (`&acao=`). O hash leva só identificadores (`&processo=1` ou `&publicacao=<id>`, só dígitos) e a URL sai dos data-atributos que o Twig grava na tag do script (`data-url-vincular-processo`, `data-url-vincular-publicacao`). `push-processos-pasta.js` deixa de mandar `&acao=`.
- Auditoria 4.04 (item 1): a senha de dev que estava em 15 arquivos versionados (docs, `scripts/carregar-fixture-dev.sh` e o help do `app:create-super-admin`) saiu. A guarda `verificar-segredos.py` ganhou a regra `senha-vazada` (sha256 do valor), que recusa essa senha em qualquer arquivo, mesmo com `segredo:permitido`.
- `docker-compose.yml`: o Postgres de dev publica a porta 5432 só no `127.0.0.1`.

## Para o cliente
- Correção de segurança na tela do Expediente: um link preparado por outra pessoa não consegue mais fazer o sistema enviar dados para outro lugar quando você cria uma pasta a partir do Push Processual. O uso normal continua igual.
- Reforço interno de segurança: senhas de teste deixaram de aparecer na documentação do sistema e o banco do ambiente de desenvolvimento só aceita conexões da própria máquina.
