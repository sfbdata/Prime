---
tipo: novidade
---
## Changelog
- Função Twig `produto_inclui('x')` (aceita lista; basta um) e `produto_rota_inicial()` em `App\Produto\Twig\ProdutoExtension`, sobre o serviço `ProdutosNaTela` (mesma régua da guarda: tudo com a chave `MULTIPRODUTO_MODO` desligada, sem login, para o Master ou sem escritório na sessão).
- Menu lateral, barra secundária do Expediente, modal, atalho e pendência do Ponto, lembretes da Agenda e busca rápida passam a seguir os produtos do escritório quando a chave estiver ligada. A barra secundária fica, vazia, para manter a altura do cabeçalho.
- O logo leva à tela inicial do escritório (`PaginaInicialDoEscritorio`): o Expediente para todos os escritórios de hoje, o Voto para quem nasceu pelo site do Voto.
- `ia_disponibilidade()` responde "desligada no escritório" para escritório sem a I.A. (chave ligada).
- Cadastro: o "Criar conta" avisa quando a conta vai nascer só com o Voto e oferece a conta com todas as ferramentas (`/cadastro?produto=completo` tira o produto da sessão).

## Para o cliente
- Quem chega pelo site do BlueJus Voto vê, na tela de criar conta, que a conta vai nascer só com o Voto, e pode escolher a conta com todas as ferramentas com um clique.
- Preparamos o menu para mostrar a cada escritório só as ferramentas que ele usa. Por enquanto isso fica desligado: o menu continua com os mesmos itens de sempre, e o logo do topo continua levando ao Expediente (só a conta criada pelo site do Voto abre no Voto).
