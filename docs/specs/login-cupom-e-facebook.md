# Login: cupom de indicação no cadastro e Continuar com Facebook (desenho 10 VS 25.10.26)

Risco ALTO (identidade User/Tenant). Desenho: `docs/design/redesign-25-10-26/10 - Login - VS 25.10.26.dc.html`.
Base: a tela já segue o desenho 10 VS 24.10.26 (#366). Esta spec cobre só a diferença 24.10 → 25.10 (PR A) e o
Facebook (PR B, seção própria, escrita antes do código dele).

## Decisões (coordenador, 26/10)

- D1. Cupom: aceitar com e sem o prefixo `BJ-`; os códigos existentes (8 caracteres de `CodigoDeIndicacao::ALFABETO`)
  não mudam.
- D2. BJMonitor do desenho (`bj-monitor.js`, `BJMonitor.reg('login', ...)`): fora. Não existe `/master/saude`.
- D3. "· você ganha 5 GB extras no 1º mês": promete um bônus que o sistema não tem. Fica sem o texto até o dono decidir.
- D4. E-mail "Indicação aceita" ao indicador (desenho: `bj-com-fila`, gatilho "Indicação aceita", assunto "<Nome> abriu
  a conta com o seu cupom BJ-X"): **aguarda o dono**. Até lá segue a spec `indicar-bluejus.md` (comportamento 7: sem
  aviso a quem indicou).
- D5. Conferência ao vivo do cupom ("Cupom válido: indicado por <nome>" / "Cupom não encontrado..."): **aguarda o dono**
  (revela se o código existe e o nome de quem indicou; o `/i/{codigo}` foi feito para não revelar). Até lá o cupom é
  validado só ao enviar, em silêncio, como o link curto.

## PR A: tela e cupom (sem migration)

Comportamento:

1. Título do modal centralizado em todos os passos; no "Criar conta e escritório" sem o ícone (`mdTemIc: md !== 'cad'`).
2. Entre "Nome do escritório" e "E-mail", o botão-chave "Tenho um cupom de indicação" (chave 36x20, desligada `#c9d3da`
   com bolinha `#fff` à esquerda; ligada `#f26b55` com bolinha `#12242f` à direita, transições de .2s). Ligada, mostra o
   campo `BJ-XXXXXX` (monoespaçado, negrito 14.5px, `letter-spacing:1px`, caixa alta, foco no campo). Desligar apaga o
   campo. O campo aceita só `A-Z`, `0-9` e `-`, em caixa alta, até 11 caracteres (`BJ-` + 8; o desenho corta em 10
   porque o protótipo usa 6).
3. A tela abre no "Criar conta" com o cupom preenchido (`BJ-` + código, chave ligada) quando a URL traz `?ind=`,
   `?codigo=` ou `?ref=` com um código no formato (`CodigoDeIndicacao::doCupom`: `[A-Z0-9]{4,12}`, sem diferenciar
   maiúsculas, com ou sem o prefixo, D1). O prefixo é `BJ-`; `BJ` sem hífen só é prefixo quando sobra um código inteiro
   de 8, porque o alfabeto tem B e J e `BJXY2345` é um código real. `#cadastro` na URL também abre o "Criar conta" e,
   aberto pela URL, o foco vai para o cupom (`autoFocus` do desenho). Valor fora do formato é ignorado.
4. O link curto `/i/{codigo}` continua guardando o código na sessão (conferido, nulo se não existe) e passa a levar a
   `/cadastro?ind=<CODIGO>`, que leva ao login com `abrir=cadastro&ind=<CODIGO>`. O `ind` é o que veio na URL, em caixa
   alta, se tiver o formato; não depende de o código existir, então a resposta continua sem revelar nada (mesmo destino,
   mesmos cabeçalhos para código válido e inválido).
5. Ao enviar ("Criar minha conta grátis", pelo e-mail ou conectado pelo Google), o JSON leva `indicacao` quando a chave
   está ligada, e leva `indicacao` vazio quando a pessoa desligou um cupom que veio na URL. Com a chave desligada e sem
   cupom na URL, `indicacao` não vai: quem chegou pelo link curto e cria a conta em outra aba não perde a indicação da
   sessão. Quando `indicacao` vem no corpo, o campo é a palavra final e vale no lugar do código da sessão; vazio,
   malformado ou inexistente não liga nada. Quem lê o corpo é o `CadastroPeloLinkListener`, no evento
   `ContaCriadaPeloCadastro` da própria requisição: os controllers do Auth não mudam e o Auth continua sem conhecer a
   Indicação na criação da conta (comportamento 7 da `indicar-bluejus.md`). Cupom inválido nunca derruba o cadastro e
   não mostra erro.
6. "Continuar com Google" no cadastro sai da página: o cupom ligado é guardado no `sessionStorage` só no clique do botão
   e volta ao campo na volta do Google (conectado); depois sai do `sessionStorage`. Cupom da URL desligado antes do
   clique também é guardado (como marca de desligado) e, na volta, o envio leva `indicacao` vazio, como no item 5.
7. Acoplamento aceito: a tela de entrada (`SecurityController`) e o `GET /cadastro` usam `CodigoDeIndicacao::doCupom`
   só para o formato do cupom da URL; nenhuma regra de atribuição sai da Indicação.

Fora do PR A: D2, D3, D4, D5 e o Facebook (PR B).

Testes:

- Funcional do login: `?ind=BJ-QWER2345`, `?codigo=qwer2345` e `?ref=` chegam ao `data-ind` em `BJ-QWER2345`; valor fora
  do formato não chega; a chave e o campo são filhos diretos do passo `cad`, entre o escritório e o e-mail.
- Funcional do cadastro: `indicacao` válido liga a conta nova a quem indicou; com e sem `BJ-`; inválido não liga e não
  derruba; `indicacao` vazio desfaz o código do link curto; sem `indicacao` no corpo, o link curto vale como hoje; o
  mesmo pelo `/cadastro/google/criar`.
- Funcional do link curto: destino `/cadastro?ind=<CODIGO>` igual para código válido e inválido.

## PR B: Continuar com Facebook

Escrita antes do código do PR B. Migration de número 150800 (dado pelo coordenador; a 150700 é de outra frente).
