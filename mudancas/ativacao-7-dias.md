---
tipo: novidade
---
## Changelog
- Comercial: plano de ativação de 7 dias para quem acabou de se cadastrar. O dia 1 é a boas-vindas (`val`), agora com o passo a passo da primeira pasta e lugar para o botão do vídeo (aparece quando houver link). Os dias 2 a 7 são seis automações novas (`at2` a `at7`) na lista da Central de envios, cada uma com liga/desliga e cópia editável na grade de modelos (`ativ2` a `ativ7`). Nascem desligadas.
- A varredura de 15 em 15 minutos manda o passo do dia N quando a confirmação do cadastro faz N-1 dias (tolerância de 2 dias para worker parado), um passo por vez, sem repetir: o que já saiu vem do registro de envios. O dia 6 vai só a quem está num escritório sem nenhuma pasta. Os passos são e-mails de lista (`comercial_dicas`): respeitam o descadastro e medem a leitura.
- Migration Version20261126140000: só as 6 linhas novas em `comercial_automacao`, sem mudança de esquema.

## Para o cliente
- Quem cria uma conta no BlueJus passa a receber, na primeira semana, um e-mail por dia com dicas para começar: criar a primeira pasta, anexar documentos, organizar prazos, funções que economizam tempo, uma oferta de ajuda para quem ainda não criou nenhuma pasta e, no sétimo dia, um pedido de sugestões.
- O e-mail de boas-vindas agora traz o passo a passo para criar a primeira pasta.
- No Painel do Master, a Central de envios ganhou as seis automações do plano de ativação, cada uma com o seu botão de ligar e desligar e o texto editável.
