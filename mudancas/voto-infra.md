---
tipo: interno
---
## Changelog
- BlueJus Voto, fase A2 (docs/specs/bluejus-voto.md, D1-B e D2): infraestrutura do container próprio do Voto no repositório, sem ligar nada em produção. Subir é passo manual do Samuel pelo novo docs/runbooks/voto-subir-na-vps.md.
- Migration Version20261109160000: papel `voto_acesso` (NOLOGIN, criado só se não existir), `USAGE` no schema `voto`, privilégio padrão de leitura e escrita só nas tabelas e sequências futuras do schema e leitura da `public.recurso_estado` (chave do Master); a `voto.liberacao` e o resto do `public` ficam de fora. O usuário `voto_app` é criado à mão na VPS.
- Papel do container: `App\Voto\EventListener\PapelDoContainer` (kernel.request, prioridade 16) lê `APP_PAPEL`; no papel `voto` só as rotas `voto_publico_` respondem, no BlueJus (variável vazia ou ausente) elas são 404. Nova rota pública `voto_publico_saude` (`GET /saude-do-voto`, texto `ok`, sem cache; linha nova no `access_control` do security.yaml), atrás da chave `voto` do Master. O papel vai no atributo `_papel` da requisição, e a página "em preparação" esconde o "Voltar ao Expediente" no papel `voto`.
- docker-compose.prod.yml: serviço `voto` só com o profile `voto`, sem porta e sem `env_file` (não recebe o .env.prod do BlueJus: só `APP_PAPEL=voto`, banco por `VOTO_DATABASE_URL`, chave própria `VOTO_APP_SECRET`, endereço e hosts aceitos fixos em voto.bluejus.com.br e valores inofensivos nas variáveis obrigatórias da configuração; .env.prod.example ganha as duas chaves VOTO_ vazias), pool PHP-FPM próprio (docker/voto/php-fpm-voto.conf, 10 processos, sem log de acesso), 768 MB e 1 CPU, entrypoint próprio (bin/entrypoint.voto.sh) que recusa subir sem banco ou sem chave, sem migrations nem cópia de assets.
- nginx: scripts/vps/vhosts/voto.conf.https-pendente para voto.bluejus.com.br, com limites por IP próprios, `camera=(self)` e sem `access_log` em `/urna` e `/autorizar`. Nada novo em nginx/conf.d.
- Teste de arquitetura `VotoInfraArquiteturaTest` trava o profile, o Voto sem segredo do BlueJus, as variáveis obrigatórias da configuração no ambiente do Voto, o vhost pendente e os scripts e workflows sem profile; os testes do Voto preparam sozinhos o papel `voto_acesso` (o snapshot de teste não leva papéis); restaurar-backup.md avisa do papel `voto_acesso` num servidor de banco novo.

## Para o cliente
- Preparamos a estrutura em que o BlueJus Voto vai rodar, separada do restante do sistema, para uma votação com muitos participantes não deixar o BlueJus lento. O Voto continua em preparação e ainda não aparece para os escritórios.
