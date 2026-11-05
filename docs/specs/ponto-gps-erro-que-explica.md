# Ponto: o aviso de GPS diz o motivo certo

Risco ALTO (ponto eletrônico). Só tela: a validação de raio do servidor não muda.

## Problema

`erroGps()` em `app/templates/ponto/index.html.twig` dizia sempre "Permita o acesso à localização para
registrar o ponto.", para qualquer erro. Quem já tinha permitido (o erro era posição indisponível ou
demora) procurava uma permissão que estava concedida, sem caminho de recuperação na tela.

## Regra

`GeolocationPositionError.code`:

| Código | Aviso em `#gps-status` | Botão |
|---|---|---|
| 2 POSITION_UNAVAILABLE | "Não foi possível descobrir sua localização agora. Tente de novo; no computador, deixe o Wi-Fi ligado." | Tentar de novo |
| 3 TIMEOUT | "A busca pela sua localização demorou demais. Tente de novo; no computador, deixe o Wi-Fi ligado." | Tentar de novo |
| 1 PERMISSION_DENIED | depende de `navigator.permissions.query({name:'geolocation'})`, abaixo | |

Estado da permissão no erro 1:

- `prompt`: pede a permissão; o botão "Permitir localização" chama `getCurrentPosition`, que abre a janela do navegador.
- `denied`: o navegador não reabre a janela. Passo a passo numerado (ícone à esquerda do endereço, Localização, Permitir, recarregar), com variação para celular (Permissões, e o caminho do iPhone em Ajustes).
- `granted`: o site tem permissão, mas o aparelho bloqueia (localização do sistema desligada). Explica e mostra "Tentar de novo"; não tenta sozinho, para não entrar em laço.
- sem `navigator.permissions` (Safari antigo): o passo a passo.
- `prompt` de novo logo depois do clique em "Permitir localização" (Firefox com "Bloquear" sem lembrar): o passo a passo, para não redesenhar o mesmo botão.

Computador ou celular: user agent, mais `Macintosh` com tela de toque (iPad). O texto diz "Toque" no celular e "Clique" no computador; os passos do computador trazem o caminho do Safari do Mac.

Só a busca mais nova fala no aviso: cada `iniciarGps()` ganha um número, e o erro de uma busca antiga (dois cliques seguidos) é ignorado.

`onchange` da permissão: virou `granted` com a página aberta, a busca recomeça sozinha (getCurrentPosition e um `watchPosition` novo, porque o do bloqueio morreu); virou `prompt` ou `denied`, o aviso acompanha.

## Invariantes que continuam

- Só o envio desabilita o botão do ponto (`BotaoDoPontoRespondeAoToqueTest`); o erro de GPS não toca no botão.
- Posição boa já na mão (pela atualização contínua): a falha de uma leitura (2 ou 3) não apaga o aviso.
- Texto montado por DOM, `textContent`; sem travessão.

Testes: `app/tests/Ponto/Unit/AvisoDoGpsDizOMotivoTest.php` (arranjo do fonte; PHPUnit não roda JS). O comportamento no navegador é smoke do dono.
