---
tipo: novidade
---
## Changelog
- Painel do Master, "Contas e escritórios" (desenho Perfis 1.2): o menu da conta ganha "Suspender conta" ou "Reativar conta", conforme a situação, com a confirmação do desenho. A conta suspensa fica sem acesso para os usuários no request seguinte, sem apagar nenhum dado; reativar devolve o acesso.
- Novas rotas POST /master/contas/{id}/suspender e /master/contas/{id}/reativar (só Master, CSRF por conta, resposta JSON para o painel). A lista /master/dados/contas passa a dizer se a conta foi criada por um Master (ehDoMaster).
- A conta criada por um Master (ou, sem criador registrado, com algum Master de vínculo ativo) não pode ser suspensa nem excluída: a recusa vale no painel e nas rotas antigas de exclusão (escritorio_excluir e app_tenant_delete).
- Suspender e reativar ficam na trilha de auditoria com a conta afetada ("Conta suspensa" ou "Conta reativada", detalhe "Ação confirmada pelo Master"), e não no escritório em que o Master está. A exclusão de conta passa a aparecer como "Conta excluída", não mais como suspensão.
- A conta suspensa continua ocupando vaga no limite de escritórios próprios; só a excluída libera a vaga.
- Conta suspensa barra o acesso com o aviso "A conta do escritório {nome} está suspensa. Fale com o responsável pela conta.", no redirecionamento e na tela de seleção de escritório (para quem só tem vínculo com conta suspensa). As barreiras de acesso ao escritório ficam em métodos próprios: primeiro a conta, depois o vínculo.

- Painel do Master: "Excluir conta" no menu da conta (oculto na conta do Master), com confirmação que exige digitar EXCLUIR, conferida também no servidor (POST /master/contas/{id}/excluir). A conta sai do ar na hora e fica em quarentena pela carência configurada (365 dias), depois da qual a purga automática a apaga; os usuários nunca são apagados. Excluir também vale para conta suspensa.
- "Contas e escritórios" ganha no filtro a opção "Em quarentena", com a data prevista da purga. "Reativar conta" recupera a conta da quarentena; a trilha registra "Conta excluída" e "Exclusão de conta cancelada", com "Ação confirmada pelo Master".

## Para o cliente
- Quando a conta do escritório estiver suspensa, a tela de seleção de escritório passa a explicar o motivo e a quem recorrer.
