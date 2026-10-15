# HANDOFF: Trabalho Externo (frente `modulo-trabalho-externo`)

Frente aberta em 15/10/2026, base `origin/master` @ `a3919b5c`. Nada publicado: sem push, merge, deploy
nem migration fora do banco de teste da frente. Spec: `docs/specs/trabalho-externo.md`. Desenho:
`docs/design/trabalho-externo/06 - Trabalho Externo 1.0.dc.html` (idêntico ao projeto do Claude Designer
`4f6a5f61-…`, conferido pelo tamanho dos três arquivos).

## O que está pronto

| Fatia | Conteúdo |
|---|---|
| F1 | domínio `App\TrabalhoExterno`, permissão `modules.trabalho_externo.view`, link na barra lateral (abaixo de Push Processual, selo NOVO) e na subnav; lista, KPIs, período, chips, busca, menu de contexto; Nova solicitação e rascunho com documentos; painel lateral (Resumo, Proposta, Execução, Chat, Histórico, andamento em 8 marcos); configuração do Master (visibilidade, disponibilidade, Equipe e privacidade) |
| F2 | aba WhatsApp: contatos com consentimento, fila de mensagens por fase, envio manual pelo `wa.me`, enviadas, link da demanda |
| F3 | Convidado: convite por e-mail (só o hash do token no banco), criar conta pelo link (sem escritório), aceitar com conta existente, perfil por demanda, permissões do convite, reenviar (novo link) e revogar |

Perfis (decisões do dono 15/10): Master = `ROLE_SUPER_ADMIN`; Equipe = colaboradores do escritório-sede;
Solicitante = demais escritórios; Convidado = convite aceito e vigente.

## Para o dono executar

```bash
# Execute manualmente no terminal externo
# 0. a worktree usa o mesmo banco do dev (saas_ux) só para o smoke:
cp app/.env.local .claude/worktrees/modulo-trabalho-externo/app/.env.local
# 1. BACKUP do banco de dev e, só então, a migration desta frente (apenas ela):
docker exec jusprime_db_dev pg_dump -U symfony saas_ux > ~/backup-saas_ux-antes-trabalho-externo.sql
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/modulo-trabalho-externo/app && php -d memory_limit=1G bin/console doctrine:migrations:execute "DoctrineMigrations\Version20261015210000" --up'
# 2. escritório-sede da Central (a Equipe sai dele), ex. o escritório 1:
docker exec jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/modulo-trabalho-externo/app && php bin/console trabalho-externo:definir-sede 1'
# 3. servidor da worktree (o nginx :8080 serve o checkout principal) e a ponte para o Windows:
#    receita completa na memória reference_smoke_de_worktree_no_navegador (router em var/router-smoke.php)
docker exec -d -e APP_ENV=dev jusprime_php_dev bash -c 'cd /var/www/.claude/worktrees/modulo-trabalho-externo/app && php -S 0.0.0.0:8000 -t public var/router-smoke.php'
docker run -d --rm --name smoke-trabalho-externo-proxy --network jusprime_saas_net -p 127.0.0.1:8000:8000 alpine/socat tcp-listen:8000,fork,reuseaddr tcp-connect:jusprime_php_dev:8000
# abrir http://127.0.0.1:8000/trabalho-externo  (descartar depois: docker rm -f smoke-trabalho-externo-proxy)
# desfazer a migration no dev, se quiser: ... --down (ou restaurar o backup)
```

- **Master global (D1)**: hoje o `ROLE_SUPER_ADMIN` está com `jusprime.samuel@gmail.com`; o Farlei só tem
  `ROLE_USER`. Trocar o papel é dado de produção (e muda o acesso de suporte do sistema inteiro).
- O banco `saas` está atrás do `origin/master` (migrations de outras frentes não aplicadas): o
  `migrations:migrate` completo ali levaria tudo junto. Por isso o comando acima executa só esta.

## Decisões pendentes do dono

1. "Salvar edição" e "Novo escritório" do bloco Solicitante (gravariam dados do escritório a partir da
   solicitação). Hoje o seletor só preenche os campos.
2. Onde o Master define a sede na tela (hoje só por comando; o desenho não tem lugar).
3. Notificação no sino (o texto do desenho diz "o solicitante é notificado"); não feito para não tocar
   a `Notificacao`, que a frente `designer-central-ia` também altera.
4. Link "Trabalho Externo" na barra lateral para quem é só Convidado (sem escritório): hoje ele entra
   pelo link do e-mail; a barra lateral não mostra o módulo para ele.
5. Auditoria técnica (`audit_log`): o módulo entrou como "não auditável" (domínio novo, como
   Processo/Cliente/DJEN); a demanda tem o próprio histórico de domínio. Expandir é decisão sua.
6. O Convidado lê o chat só a partir do aceite do convite (o anterior, com o valor da proposta, fica
   oculto). Confirmar se é isso, ou se ele deve ler a conversa inteira.
7. Valores iniciais do formulário: o protótipo trazia "Brasília" como município de execução; aqui
   nasce vazio (é obrigatório).
8. **Reabrir depois do aceite do convite** (achado da revisão de segurança de 15/10, NÃO implementado).
   Hoje, se a Central reabre uma demanda concluída e renegocia, o convite continua vigente e o
   Convidado lê a renegociação (motivos, "Proposta v2 enviada: R$ ..."), porque ela é posterior ao
   aceite. Alternativas:
   - (a) **revogar os convites ao reabrir**: corte limpo, o profissional some da demanda e precisa de
     convite novo (com corte novo). Mais seguro; custo: reconvidar quem vai continuar.
   - (b) **suspender o convite enquanto a demanda estiver fora de execução** e recalcular o corte na
     volta (o que aconteceu entre a reabertura e o novo aceite fica reservado). Preserva o vínculo;
     exige um marco novo no convite (coluna e migration).
   - (c) **aceitar o comportamento**: o Convidado já é da casa depois do aceite. Sem custo; contraria
     a regra "negociação é da Central com o cliente".
9. **Ler o chat sem "Conversar no chat"**: hoje, com a permissão desligada, o Convidado não escreve,
   mas lê o chat, recebe a prévia e o contador. Alternativas: (a) manter (a permissão é de escrever,
   como o rótulo diz); (b) esconder a aba Chat, a prévia e o contador sem a permissão (o histórico e
   o andamento continuam). (b) é uma linha na `PoliticaDaDemanda::mensagemVisivel` mais testes;
   muda o que o desenho mostra para esse caso.

## Diferenças em relação ao desenho

Declaradas na spec §7 e §8. As principais: moldura do sistema no lugar do cabeçalho/subnav do protótipo;
seletor "Simula o acesso de cada perfil" não existe (o perfil vem do servidor); sem aviso em tempo real
entre abas (o aviso de não lidas aparece ao carregar); aba bloqueada também barra a criação no servidor;
quem já tem conta precisa abrir o link de novo depois de entrar.

## Testes

`tests/TrabalhoExterno/` (unit: política, entidade, valores em reais; functional: fluxo completo,
isolamento entre escritórios, perfis, CSRF, rascunho, central suspensa, Equipe, chat, execução, WhatsApp,
convite). Suíte completa da frente no `85c3b650`: 9.247 testes, 51.867 asserções, OK. Depois dela, `e14ec9ef` e
`e0869114` (passado da demanda filtrado para o Convidado, achados A, B e C): `tests/TrabalhoExterno`
69 testes OK; a suíte completa ainda não rodou sobre eles. Afetados fora do domínio e verdes: `TenantAwareCoberturaTest` (teto de FORA_DO_ESCOPO 8 → 18,
justificado), `MapaDeChaveParaCaminhoLocalTest`, `NucleoDeArmazenamentoArquiteturaTest`,
`FabricasDeArquivoNovoTest`, `PurgaCoberturaSchemaTest`, `PurgarEscritorioUseCaseTest`.

Rodar: `scripts/frente-testar.sh modulo-trabalho-externo tests/TrabalhoExterno`.

## Arquivos compartilhados tocados (cuidado no merge)

`templates/_sidebar.html.twig`, `templates/base.html.twig` (subnav), `config/services.yaml`,
`config/packages/doctrine.yaml`, `config/packages/security.yaml`, `src/Shared/Armazenamento/{CategoriaDeArquivo,ResolvedorDeCaminhoLocal}.php`
(conflito provável com a frente `r2-gravacao-dupla`), `src/Tenant/UseCase/PurgarEscritorioUseCase.php`,
`src/EventListener/{TenantContextValidatorListener,TermoAceiteListener}.php`, `src/DataFixtures/PermissionFixture.php`.

## Como testar localmente

Ver o fim da mensagem de entrega da frente (passo a passo por perfil). O smoke no navegador é do dono.

## Demonstração local isolada (15/10)

Banco próprio `saas_demo_trabalho_externo` (clone do banco de teste da frente, que já tem a migration;
nenhum banco existente é tocado), servidor embutido da worktree na porta **8020** e o proxy
`smoke-trabalho-externo-proxy`. Andaime fora do git em `app/var/demo-trabalho-externo/` (`env.sh`,
`seed.php`, `verificar.php`): o seed passa pelos UseCases do módulo e só aceita banco sem demandas.
Dados 100% fictícios, senha `Demo1234!`. Desmontar:

```bash
# Execute manualmente no terminal externo
docker rm -f smoke-trabalho-externo-proxy
# parar o php -S da porta 8020 (sem ps no container: localizar por /proc/*/cmdline e kill)
docker exec jusprime_db_dev psql -U symfony -d postgres -c 'DROP DATABASE "saas_demo_trabalho_externo"'
```
