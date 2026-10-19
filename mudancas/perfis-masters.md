---
tipo: novidade
---
## Changelog
- Painel do Master, aba Permissões (desenho Perfis 1.2): passa a haver Master primário e Masters secundários. O Master primário promove um usuário secundário do escritório em que está a Master secundário e remove Masters secundários, sempre com confirmação na tela; o Master primário nunca é removido pela tela (cadeado). Master secundário vê a lista sem as ações.
- Promoção e remoção de Master ficam na trilha de auditoria (troca de papéis do usuário).
- Novo papel ROLE_MASTER_PRIMARIO, que só marca o Master primário. A migration o dá ao Master atual quando ele é o único; com zero ou mais de um, o dono escolhe com app:master:definir.
- app:master:definir passa a definir o Master primário (os demais Masters perdem o papel, como antes) e app:create-super-admin cria o primeiro Master já primário.
- O painel do Master abre direto na aba e na seção indicadas no endereço (#aba=perm, #sec=contas).
