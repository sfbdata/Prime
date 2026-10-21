---
tipo: seguranca
---
## Changelog
- Hierarquia de perfis (spec perfis-1.2-permissoes-e-hierarquia, parte C): fecha a escalada em que um secundário com `admin.roles.manage` editava o próprio perfil, marcava qualquer permissão, criava perfis com tudo e excluía o perfil de um colega. Serviço novo `App\Tenant\Service\HierarquiaDePerfis` (níveis: Master 3, primário 2, secundário 1), aplicado no servidor no criar, editar e excluir perfil. O secundário com `admin.roles.manage` fica só com a leitura da lista de secundários; o perfil de sistema sai da lista dele.
- Na lista de Perfis, editar, excluir e "Novo Perfil" ficam desabilitados para quem não pode, com a mensagem "Seu perfil não pode editar um perfil do mesmo nível ou acima."
- Quem não tem acesso aos Perfis vê a tela "Acesso restrito" (continua 403) com "Pedir acesso": avisa os primários ativos do escritório pela Central, um pedido por pessoa por dia (rota `app_tenant_role_pedir_acesso`, tipo de aviso `perfis_pedido_acesso`). Sem tabela nova: o próprio aviso guarda o pedido.
- Edit-role (`admin.users.manage`) e convite de escritório (`admin.users.invite`): quem não é primário nem Master não troca o próprio perfil e só atribui perfil cujas permissões estejam contidas nas dele. Primário e Master sem mudança.
- Painel do Master, Contas e escritórios (parte A): selo com o número da conta (o mesmo "conta Nº 000123" do Contribua e do Pix, agora num ponto comum, `App\Tenant\Service\NumeroDaConta`) e busca "Buscar nº, conta ou primário", que acha o número com ou sem zeros à esquerda.
- Sem migration.

## Para o cliente
- A gestão de perfis do escritório ficou mais segura: só o usuário primário e o Master criam, editam e excluem perfis, e quem distribui perfis a colegas só pode dar acessos que ele mesmo tem. Quem não tem acesso aos Perfis pode pedir ao primário com um clique.
- No Painel do Master, cada conta mostra o número dela e a busca aceita esse número.
