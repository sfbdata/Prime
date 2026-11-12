# Voto: cabine de votação (urna virtual)

Desenho: `docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html` (L71-111 CSS, L257-280 marcação, L331-345 JS).
Contexto: a assembleia de condomínio publicada (v2.48.0) votava numa cédula em lista. O Dono pediu a urna do desenho.
Risco: MÉDIO (tela do voto; a gravação continua no mesmo UseCase e na mesma rota).

## Contrato

- A cabine é só a tela. Identificação (código por e-mail), token, CSRF, regras e gravação não mudam
  (`RegistrarVotoUseCase`, `POST /votar/{token}/pauta/{pauta}`).
- Cada pauta aberta que a unidade ainda não votou é um "cargo" da cabine, na ordem das pautas. O eleitor digita o número da
  opção; BRANCO é a ABSTENÇÃO (o desenho já chama assim quando a votação é de pauta); CONFIRMA passa à próxima pauta.
- Ao confirmar a última, os votos seguem um a um para o POST da cédula com `Accept: application/json` (resposta
  `{ok, mensagem}`; 400 sem CSRF, 422 recusa do UseCase). Sem `Accept: application/json` o POST continua redirecionando com
  flash como antes. Nada é gravado antes do fim; se uma pauta falhar no meio, a tela de erro mostra a mensagem do servidor e
  as já gravadas ficam gravadas ("Você já votou nesta pauta" ao recarregar).
- Botões na cédula (L153): "Conhecer candidaturas", "Simular votação" e "Votar" (desabilitado sem pauta a votar). Saíram as
  opções dentro do cartão da pauta e a janela de confirmação antiga (o desenho confirma na tecla CONFIRMA).
- Reserva sem a cabine (extra, pedido do Samuel): o voto simples de antes (um botão por opção, envio direto) fica no
  cartão da pauta, escondido (`.vcReserva[hidden]`). Se `js/voto-cabine.js` não subir, o guarda da cédula (script inline)
  insiste no arquivo 2 vezes (a cada 2,5 s), recarrega a página 1 vez (`sessionStorage vc_recarregou`) e só então mostra a
  reserva e esconde os botões da cabine. Com JavaScript desligado, o `<noscript>` mostra a reserva. A cabine, ao subir,
  esconde a reserva. A reserva envia sem a confirmação (como a cédula antiga sem JavaScript).
- Simulação: mesma cabine com a faixa "SIMULAÇÃO: nenhum voto é registrado"; nada é enviado.
- Teclado físico (números, B, Backspace, Enter), sons (WebAudio, botão liga e desliga), LEDs, braile, `aria-live`,
  `prefers-reduced-motion`, responsivo (760px): como no desenho.

## Desvios e itens fora (decisão do Samuel pendente onde marcado)

| Item do desenho | Cabine entregue | Motivo |
|---|---|---|
| Tela "Confirme sua identidade" com PIN (`fase:'auth'`) | não entra | `/votar/{token}` só abre depois da identificação; o sistema não tem PIN nem pedido de código nesta rota |
| Voto nulo (número sem opção) | mostra "NÚMERO INVÁLIDO. Aperte CORRIGE e informe um número válido." e CONFIRMA recusa até o número ser válido | decisão do Samuel (12/11): número errado não é voto nulo, o eleitor é avisado |
| Comprovante de depósito e imagem (`uReal`) | entra (seção "Comprovante") | pedido do Samuel (12/11) |
| Comprovante por e-mail, "Verificar urna", "Pacote público", boletins | não entram | e-mail do comprovante, urna cifrada e boletins são de outras frentes |
| Rodapé "Demonstração, sem vínculo..." | sem a palavra "Demonstração" | contrato da cédula: não é demonstração |
| "Protegendo seu voto / Cifrando a cédula" | "Registrando seu voto / Enviando seu voto e separando-o da sua identidade" | não há criptografia (contrato da cédula) |
| Foto, descrição e chapa da candidatura; vagas múltiplas; "Próxima votação" | não existem | a pauta é Sim/Não/Abstenção; sem esses dados |
| "Conhecer candidaturas" | lista as opções das pautas abertas (iniciais no `.vAv`) | sem foto nem descrição |

## Comprovante (12/11)

- Cada voto registrado devolve um código `BJV-XXXX-XXXX-XXXX` (HMAC da unidade, da pauta e da hora da participação em
  segundos, com o segredo da aplicação; `ComprovanteDoVoto`). Prova que a unidade votou e quando, nunca em quê: a
  participação não guarda a opção. Sem migration: o código é refeito a partir de `voto.assembleia_participacao`.
- `RegistrarVotoUseCase::executar` devolve o código; o ramo JSON de `CedulaController::votar` o inclui em `comprovante`.
- Tela FIM da cabine: "Comprovante de depósito da cédula (não revela seu voto)" com um código por pauta e o botão
  "Comprovante em imagem" (PNG gerado no aparelho, sem subir nada).
- Conferência pública no portal (`/voto/v/{codigo}`): "Confira seu comprovante" + "Conferir"
  (`POST /voto/v/{codigo}/comprovante`, CSRF do portal e o mesmo limitador de tentativas da confirmação do código).
  Aceita minúsculas e sem traços. Mostra só a pauta, a data e a hora; nunca a unidade nem a opção.

## Arquivos

`app/templates/voto/cedula/_cabine.html.twig`, `cedula.html.twig`, `_pauta.html.twig`, `_layout.html.twig`;
`app/public/js/voto-cabine.js`, `app/public/css/voto-cabine.css`, `app/public/images/voto/bj-voto-simbolo.png`;
`CedulaController::votar` (ramo JSON); traduções em `voto.assembleia.cedula.cabine.*`; testes em `CedulaControllerTest`.

## O que olhar na tela (smoke do dono)

Abrir `/votar/{token}` com pauta aberta: botões Votar, Simular e Conhecer; urna abre com animação; digitar 1, 2 e B no
teclado da tela e no físico; CORRIGE; número errado; CONFIRMA na última pauta (barra e anel FIM); recarregar mostra "Você já
votou"; som, LEDs e celular.
