---
tipo: alteracao
---
## Changelog
- Toda pasta nova nasce com quem a criou como responsável: Nova pasta do Expediente, Nova demanda, importação em lote, cadastro pelo Chat I.A e pasta judicial aberta pela Cobrança. O acervo por CSV, a descoberta pelo Drive e a correção de pasta judicial duplicada continuam criando sem responsável. Pastas antigas não mudam. Primeira fatia de `docs/specs/acesso-restrito-e-compartilhar.md` (regra 6).
- Base do "acesso restrito às pastas sob responsabilidade" (segunda fatia): o perfil ganha a opção no banco (`tenant_role.escopo_pasta` e `rotulo`) e o servidor passa a aplicá-la. Quem tem o perfil restrito só alcança as pastas em que é o responsável (pasta alheia dá 404 e some da Lista, do CSV, das contagens, da busca Ctrl+K e das setas da pasta), perde todas as permissões fora da lista de liberação e recebe a tela "Fora do seu acesso" nas telas do escritório; também não troca o responsável. Ainda não há como ligar a opção: a tela de Perfis vem na fatia 4.

## Para o cliente
- Ao abrir uma pasta nova, você já fica como o responsável por ela, inclusive quando importa várias pastas de uma vez. Se for outra pessoa, é só trocar o responsável na lista, como antes. As pastas que já existem continuam como estão.
- Preparamos o sistema para um novo tipo de perfil: o colaborador que só enxerga as pastas pelas quais é responsável. Ainda não aparece nas telas; quando a opção chegar à tela de Perfis, avisaremos.
