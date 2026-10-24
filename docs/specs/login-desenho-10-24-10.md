# Login do desenho 10, VS 24.10.26 (delta sobre a VS 19.10.26)

**Risco:** ALTO (tela de identidade) · **Frente:** `login-24-10-26` (Reserva B) · **Data:** 24/10/2026
**Desenho:** `docs/design/redesign-24-10-26/10 - Login - VS 24.10.26.dc.html` · **Base:** `docs/specs/login-desenho-10.md`
(VS 19.10.26, PRs #101 #106 #107 #112 #115 #119 #123).

## O que mudou no desenho (diff completo das duas versões)

| # | Mudança | Decisão |
|---|---|---|
| a | `<script src="./bj-tema.js">`: o tema comemorativo passa a valer no Login | entra nesta frente |
| b | Placeholder do Nome do escritório: "Ex.: BlueJus Advogados Associados" | entra nesta frente |
| c | Criar conta com "Continuar com Google" / "Continuar com Facebook", cartão da conta conectada ("nome · e-mail · conectado pelo X", "Usar outro e-mail") e E-mail/Senha escondidos quando conectado | **fora**: aguarda o dono (via coordenador, 24/10). O "Entrar com o Google" está em STANDBY desde 21/10 (branch `entrar-com-google`); o Facebook não tem app nem credencial. Os botões não entram até a resposta |

## (a) Tema comemorativo no login, como o `aplicar()` do bj-tema.js

O desenho, na página do Login, aplica as cores, a fita e os ícones da tarja, e **sai antes do selo**
(`if (/Login|.../.test(location.pathname)) return;`). Logo:

1. **Cores** (`css(t)` do desenho, só nos azuis que existem na tela):
   - fundo `#0a76a8` → primário do tema, sem degradê: tarja, topo do modal (Criar conta / Esqueci a senha), topo das
     ferramentas SÓ MASTER (`.lg-lk__topo`) e topo do Falar com o suporte;
   - degradê com `#0a76a8` → `linear-gradient(135deg, primário, escuro)`: topo do painel Segurança e privacidade e o véu
     "Abrindo o seu escritório digital" (no login e na página `/login/entrando`, que é o véu do desenho);
   - texto `#0a6fa0` → primário: ícones dos itens do painel Segurança e privacidade.
   - O azul de ação `#0a63c9` (botões, links) não é trocado pelo desenho e fica.
2. **Fita** de 4px no topo, animada no Dia das Crianças.
3. **Ícones** flutuando na tarja (5 posições, os mesmos do cabeçalho do sistema), ocupando a tarja inteira; somem
   abaixo de 640px.
4. **Brilho** nos logos `bluejus-logo*` (tarja, cartão, painel de segurança, barra do modal) no tema animado.
5. **Sem selo nem botão redondo.**
6. Prévia do Master: vale também aqui, pelo mesmo `localStorage` do card de controle (o Master logado vê o login).

Implementação: reaproveita o parcial `layout/_globais/tema_comemorativo.html.twig` e o `public/js/tema-comemorativo.js`
com o modo `bjtSemSelo` (atributo `data-sem-selo`): nada novo no servidor, nenhuma rota, nenhuma migration. A tarja é
achada por `[data-bjt-cabecalho]` e os logos por `[data-bjt-logo]`. As cores trocadas moram em `css/auth/entrada.css`,
sob `html:has(#bjtCss)` (o `<style id="bjtCss">` só existe com tema ativo, e o JS da prévia o recria).

`tema_comemorativo()` é global (linha única `tema_config`), não depende de usuário nem de tenant: funciona anônimo.
O catálogo da prévia continua só para o Master.

## Testes

- `tests/Auth/Functional/EntradaTemaComemorativoTest.php`: login e véu com tema (CSS, variáveis, fita, sem selo,
  tarja e 4 logos marcados, sem catálogo para anônimo), tema animado e sem tema.
- `LoginTelaTest`: placeholder novo.
- Smoke do dono: cores, ícones e brilho na tela (nenhum teste vê aparência).
