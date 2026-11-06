# Tela do ponto: atualizar depois da batida sem recarregar a página

Risco: ALTO (ponto eletrônico). Só muda a tela (`templates/ponto/index.html.twig`). A regra da batida, o
servidor e o que é gravado não mudam.

## Problema

Depois de uma batida com sucesso, a tela fazia `window.location.reload()` 1,5 s depois. Recarregar zera a
liberação de áudio do navegador (o navegador só toca som depois de um gesto da pessoa NA página carregada).
O toque seguinte do alerta do ponto (retorno do intervalo, saída), que vem do modal global
`_modal_alerta_ponto.html.twig` com `ponto-alerta-som.js`, fica pendente até um clique. Com a pessoa longe do
computador, o clique não vem e o toque não toca.

## Regra

1. Batida com sucesso: a tela busca o próprio HTML (`fetch` da mesma URL, `GET`, `same-origin`, sem cache) e
   troca só o que muda com a batida. Nada é recalculado no navegador: os números são os do servidor, os mesmos da
   recarga.
   - `#batidas-de-hoje` (lista do dia, com o selo de duplicata);
   - `#ponto-banco-horas` (banco de horas no cabeçalho do card);
   - o card da folha dentro de `#ponto-regiao-folha` (só o card: o modal de pré-visualização e o script dele ficam,
     com os listeners que já têm);
   - `#ponto-regiao-pendencias` (pendências de registro);
   - o estado do relógio: `#clock[data-ponto-do-dia]` alimenta relógio, previsão de saída, contador do repouso e
     horas excedentes (antes, `const` lidos uma vez na carga);
   - a sugestão do `#tipo-registro` (o `selected` que o servidor calcula) e o rótulo do botão.
2. O botão fica travado do toque até a tela atualizar (mesma guarda contra a segunda batida do mesmo tipo que a
   recarga dava). Atualizou: o botão volta, já com a próxima sugestão.
3. Conferência antes de trocar: resposta 200 sem redirecionamento, todas as regiões presentes no HTML novo e o
   mesmo dia (`#clock[data-dia]`). Faltou qualquer uma: nada é trocado e a tela recarrega como antes (1,5 s
   depois). A troca é tudo ou nada.
4. Batida com avisos do servidor (`mostrarSucessoComAvisos`): os avisos ficam na tela como hoje e a tela se
   atualiza do mesmo jeito. Se a atualização falhar, o comportamento de antes: botão travado e "Atualizar"
   oferecido, sem recarregar sozinho (dá tempo de ler).
5. Os outros caminhos (recusa, sem rede, sem confirmação, botão "Atualizar e conferir", virada do dia) não mudam.
6. A busca da tela mora no módulo (`PontoBatida.buscarTelaDoPonto`), com prazo próprio (`TELA_PRAZO_MS`, 15 s) e
   recusando redirecionamento (sessão caída devolveria o login). A tela continua sem `fetch` próprio.
7. Notificação do sistema (a "janelinha" que aparece com o navegador minimizado): a permissão é pedida no CLIQUE da
   pessoa (botão da tela do ponto e atalho do cabeçalho), antes de qualquer `await`, só se ainda estiver `default`.
   Pedida só quando o aviso abria sozinho, sem gesto, o Chrome escondia o pedido e a notificação nunca saía.
8. A notificação só leva a linha "Horário: ..." quando há horário (retorno e saída chegam sem ele).

## Aba oculta e navegador minimizado

- A consulta do alerta é um `setInterval` de 60 s. Com a aba oculta o Chrome limita timers a no máximo uma vez por
  minuto (throttling intensivo depois de 5 min oculta): o intervalo de 60 s continua rodando, com atraso de até
  ~1 min.
- O `AudioContext` criado no primeiro gesto (`pointerdown`/`keydown`/`touchend`, inclusive o clique da batida)
  continua `running` com a aba oculta; o Chrome não o suspende por estar em segundo plano. O que o perdia era a
  recarga: página nova, contexto novo, sem gesto.
- O que continua fora do alcance: a pessoa NAVEGAR para outra tela do BlueJus na mesma aba (página nova, sem gesto)
  e o "Economia de memória" do Chrome descartar a aba depois de muito tempo em segundo plano (descarte recarrega).
  Nos dois casos fica a notificação do sistema, se permitida, e o toque sai no primeiro clique.

## Fora

- O polling do alerta (`ponto_alerta_horario`) já lê a batida nova do servidor; não há agenda local a refazer.
- O GPS (`watchPosition`), os listeners e os intervalos da carga continuam os mesmos: a página não é remontada.
- Valores lidos uma vez na carga e que a batida não muda continuam os da carga: home office do dia, sedes da cerca e
  o dia do aparelho. Antes a recarga os renovava de carona; agora, uma liberação de home office dada pelo gestor com a
  tela aberta só vale depois de "Atualizar" (o aviso da cerca já oferece o botão).

## Testes

- `TelaDoPontoAtualizaSemRecarregarTest` (Functional): a tela entrega as regiões com os ids e o
  `data-ponto-do-dia`/`data-dia` do relógio; depois de uma batida, o HTML que o `fetch` recebe traz a lista, a
  sugestão e o estado novos.
- Testes de texto do JS (PHPUnit, o container não tem node): sem `window.location.reload()` no caminho bom;
  recarga só como plano B quando a atualização falha.
- e2e `ponto-batida-cerca.spec.js`: depois do sucesso, o nó `#batidas-de-hoje` é novo (houve troca), o botão volta e
  a página não recarregou. A batida do e2e é simulada (nada grava); a lista com a entrada e a sugestão Repouso são
  provadas pelo `TelaDoPontoAtualizaSemRecarregarTest`.
