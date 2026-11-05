---
tipo: novidade
---
## Changelog
- Ponto: som no alerta de horário para a volta do intervalo (`retorno`) e o fim da jornada (`saida`), pedido de 05/11.
  - `public/js/ponto-alerta-som.js` (novo): som sintetizado (AudioContext, sem arquivo de áudio), duas notas subindo na volta e três descendo na saída. Só toca depois da primeira interação com a página; bloqueado, fica só o modal, sem erro no console. Um toque por tipo por dia (localStorage, chave do aviso com sufixo `_som`).
  - `_modal_alerta_ponto.html.twig`: o modal leva `data-perfil-sons` (`perfil_sons()`, "Sons de alerta" do Meu Perfil) e chama o som ao abrir. Gatilho, consulta de 60 s e `alertaHabilitado` da jornada continuam os de sempre (`VerificadorAlertaPonto`).
  - Entrada, ida ao intervalo e aviso de 6 horas continuam sem som.
  - Testes: `SomDoAlertaDePontoTest`.

## Para o cliente
- O alerta do ponto eletrônico agora toca um som quando chega a hora de voltar do intervalo e quando chega a hora de ir embora. São dois toques diferentes, um para cada aviso.
- O som toca com qualquer tela do sistema aberta, depois do primeiro clique na página (regra do navegador). Com o sistema fechado, não toca.
- Para desligar o som, use a chave "Sons de alerta" em Meu Perfil. Para desligar todo o alerta de horário, desmarque "Habilitar alertas de ponto" na jornada do escritório ou do colaborador.
