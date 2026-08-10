# Tela de entrada (`/login`) — redesenho a partir do modelo aprovado

**Data:** 2026-08-10 · **Risco:** MÉDIO · **Situação:** implementada, revisada, commitada local — **não publicada**

## Por que esta spec existe

Ela não existe por causa do risco de autenticação — esse já está coberto por teste
(`app/tests/Auth/Functional/LoginTelaTest.php` loga pelo formulário renderizado e exige a sessão
autenticada no fim). Existe porque **cinco decisões do dono e quatro desvios do desenho** só
viviam na conversa. Sem este arquivo, quem revisar daqui a três meses não distingue *desvio
autorizado* de *desvio por convicção do implementador* — que é exatamente a armadilha registrada
em `CLAUDE.md` §"Implementar a partir de um desenho — o desenho manda".

## O alvo

`docs/tela_login/login-bluejus-final.html` — HTML autocontido (CSS + HTML + JS), aprovado pelo
dono. **É a especificação visual.** A gravação `bluejus-login-final.mp4` mostra a animação e está
fora do Git de propósito (1,3 MB de binário; ver `.gitignore`).

O pedido, nas palavras do dono: *"isso é só um modelo e não para reproduzir os erros, na verdade é
para replicar só as melhorias"* — o olho de ler a senha, o aviso de Caps Lock, a animação, todos
os botões do modelo (mesmo sem destino) e o suporte apontando para um formulário externo.

## Decisões do dono (não são divergências)

| # | Decisão | Consequência |
|---|---|---|
| D1 | A tela de entrada é **sempre clara** | Não inclui `_fundo_auth.html.twig`; cores cravadas, não saem das variáveis do Bootstrap; `color-scheme:light` forçado para o checkbox nativo, o autofill e a barra de rolagem |
| D2 | URL do suporte vem de **`SUPORTE_FORM_URL`** | Nasce vazia: o botão fica na tela, inerte. Default em `services.yaml`, **não** no `.env` (o build de prod faz `rm .env`) |
| D3 | **Sem** a escalada "2ª falha → Precisa de ajuda para entrar?" | O modelo contava falhas em memória; com POST real a contagem zera a cada envio. Implementar de verdade exigiria estado no servidor — o dono dispensou |
| D4 | Termos → `public/termos/termos-de-uso.pdf`; Privacidade **inerte** | Não existe página de privacidade. LinkedIn e Instagram também inertes: o dono cria os perfis depois |
| D5 | Fonte **Source Sans 3** | A pilha do sistema operacional no modelo é fallback de quem escreveu o arquivo solto, não escolha de design |
| D6 | A animação roda **a cada carregamento**, não uma vez por sessão | Decidido no smoke de 10/08: *"só não vejo a animação quando atualizo"*. A trava `sessionStorage` do modelo caiu. Fica **uma** exceção, proposta e aceita: a tela que volta **com erro de login** não anima — falha de senha recarrega a página, e repetir 2,2s de coreografia em cima de quem está redigitando é castigo. Quem decide é o servidor (classe `anim` no HTML), não o JS |

## Desvios do desenho autorizados

Cada um conserta um defeito real do modelo. Aprovados pelo dono em 10/08 depois da revisão.

| Desvio | O que o modelo fazia | Por que mudou |
|---|---|---|
| `overflow:hidden` removido + `@media (max-height:640px)` | trancava a rolagem do body | em janela curta o botão **Entrar** ficava inalcançável |
| `padding-right:46px` no campo senha | sem folga | senha longa passava **por baixo** do olho |
| autofill medido em 120 **e** 500 ms, comparando com `defaultValue` | só 120 ms, comparando com vazio | o Chrome preenche depois de 120 ms; e o servidor devolve `last_username` após falha, o que cortava a animação sozinho |
| `keydown` além de `keyup` no Caps Lock | só `keyup` | o aviso aparecia uma tecla atrasado |

**Revertidos por não mudarem nada no tamanho do desenho:** `max-width:80%` na logo, `gap:12px` na
linha do "manter conectado", padding mobile 24px/18px (o modelo mantém 28px).

**Não é desvio:** `.check{margin:0; font-weight:400}` — o Bootstrap dá margem e peso próprios a
todo `<label>`; zerar aqui é o que **preserva** o desenho.

## Contrato do formulário — o que não pode mudar

O modelo trazia `name="senha"`, sem `_csrf_token` e com `name="lembrar"`. Qualquer um dos três,
sozinho, tranca todos os usuários do lado de fora. O que `UserAuthenticator.php:34-42` e
`security.yaml` exigem:

- `name="email"` · `name="password"` · `name="_csrf_token"` (token `authenticate`) · `name="_remember_me"` · `method="post"`
- botão de envio com o texto **"Entrar"** (dois testes o selecionam por esse rótulo)
- **exatamente um** `a[href="/senha/esqueci"]` (`RecuperacaoSenhaControllerTest` conta)

## O que os testes NÃO veem

Posição, cor, tamanho, tipografia e animação. A suíte lê HTML. Já houve neste projeto 3.459 testes
verdes com o layout visivelmente quebrado. **O smoke na tela é do dono**, e a lista está no
histórico da sessão: animação rodando uma vez e parando na primeira tecla; céu com as duas camadas;
olho revelando a senha sem o texto sumir sob o ícone; Caps Lock avisando; balão de suporte abrindo
sozinho por volta de 5s; e a tela **continuando clara** com o sistema no tema escuro.

## Pendências do dono

1. **`SUPORTE_FORM_URL`** — sem ela o botão principal do desenho não leva a lugar nenhum.
   Dev: `app/.env.local`. Prod: ambiente do container (o build apaga o `.env`).
2. Páginas de **Privacidade**, **LinkedIn** e **Instagram** — os links já estão na tela, inertes.
3. **Smoke** e publicação.

## Fora do escopo, registrado pela revisão

`ConviteController.php:110,135` usa `addFlash('sucesso', ...)`; o base gera `alert-sucesso`, classe
que não existe no Bootstrap → alerta sem cor de fundo. Pré-existente, mas agora aparece sobre o céu.
