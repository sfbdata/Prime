# Agenda: janela que orienta a conectar o Google Agenda

Pedido do dono (Farlei), 23/10/2026. Risco BAIXO (preferência de tela da própria pessoa). Sem migration:
a dispensa usa a tabela `preferencia_usuario`, que já existe.

## Objetivo

Quem abre a Agenda e ainda não ligou a conta Google vê uma janela curta que explica o ganho (compromissos no
Google Agenda e lembretes no celular) e leva ao mesmo fluxo do botão "Sincronizar com o Google Agenda".

## Regras

1. **Quando abre.** Ao abrir a Agenda (`templates/agenda/nova.html.twig` e `public/js/agenda-nova.js`), a
   janela abre SEMPRE, a cada entrada, MENOS:
   - para quem já está conectado ao Google (`EstadoDoGoogleAgendaUseCase` devolve `conectado`);
   - para quem marcou "Não mostrar esta mensagem novamente";
   - quando o servidor está sem as credenciais (`EstadoDoGoogleAgendaUseCase` devolve `indisponivel`).
   A decisão (mostrar ou não) vem do servidor, no template da Agenda, junto com o estado do Google que já é
   passado (`data-google-aviso="1"` só quando deve abrir).
2. **Componente.** A janela de pergunta da própria Agenda: desenho
   `docs/design/agenda-vs-18-10-26/09 - Agenda - VS 18.10.26.dc.html`, l.125. No código, `.ag-perg`,
   `.ag-perg-cab`, `.ag-perg-txt`, `.ag-perg-rodape` e `.ag-mbtn.is-pri` (`public/css/agenda-nova.css`),
   montada no contêiner `#agPerg`, como o `googleSincronizar()` faz. Nada de modal do Bootstrap.
3. **Textos** (catálogo de idioma, área `agenda.js.google.aviso`, sem travessão):
   - título "Sincronize com o Google Agenda";
   - texto "Conecte a sua conta Google para ver os compromissos do BlueJus no seu Google Agenda e receber
     os lembretes no celular. Use o botão Sincronizar com o Google Agenda, aqui na Agenda.";
   - caixa "Não mostrar esta mensagem novamente";
   - botões "Agora não" (secundário) e "Sincronizar agora" (primário).
   "Sincronizar agora" segue o MESMO fluxo do botão `#agGoogle` (`googleSincronizar()` e `irAoGoogle()`),
   chamando a função existente, sem copiar a lógica.
4. **Dispensa.** Só grava se a caixa estiver marcada no momento de fechar, por "Agora não" ou pelo X, ou ao
   clicar em "Sincronizar agora". Fica em `preferencia_usuario` (`PreferenciaDoUsuarioRepository::gravar`,
   por escritório e usuário), chave `agenda.google_aviso_dispensado`, valor `true`.
   - Rota própria da Agenda: `POST /agenda/google/aviso/dispensar` (`app_agenda_google_aviso_dispensar`),
     CSRF `ajax` no cabeçalho `X-CSRF-Token`, exige acesso ao módulo agenda, grava só para o usuário da
     sessão no escritório da sessão e não recebe id.
   - UseCase: `DispensarAvisoDoGoogleUseCase`: `executar()` grava; `mostrar()` decide se a janela abre.

## Desvio autorizado do desenho

- A caixa "Não mostrar esta mensagem novamente" e o X no cabeçalho não existem na janela de pergunta do
  desenho (l.125). Quem pediu foi o dono; o resto segue o componente do desenho.

## Decisão de implementação (a confirmar com o dono)

- Na entrada por link que já abre algo na Agenda, a janela não abre naquela entrada e volta na próxima, se
  ainda couber: na volta do Google (`#google=...`), o toast ou a explicação da permissão já ocupam o `#agPerg`;
  no link direto de compromisso (`#ev=<id>`, do Chat ou do lembrete), o compromisso aberto vem antes. Um teste
  só no JS (`abreAlgoPeloLink`). O Esc segue como nas outras janelas do Google.

## Testes

- Unit do UseCase (grava a chave certa com `true`, por escritório e usuário; leitura).
- Functional: aparece para quem não está conectado nem dispensou; não aparece para conectado, para quem
  dispensou, nem com `indisponivel`; o POST grava com CSRF e recusa sem ele; a dispensa é por usuário.
- Teste do JS no molde dos `*JsTest.php` (leitura do arquivo como folha).

## Estado da frente

- [x] Spec.
- [x] UseCase, rota, template, JS, CSS, catálogo e testes (23/10/2026, branch `agenda-aviso-google`).
- [x] Suíte direcionada verde (`--filter 'Agenda|Preferencia|Arquitetura|Traducao'`: 610 testes) e `scripts/qa.sh`.
- [ ] Smoke do dono na tela.
