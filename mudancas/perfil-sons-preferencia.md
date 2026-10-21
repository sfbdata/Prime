---
tipo: novidade
---
## Changelog
- Meu Perfil: a preferência "Sons de alerta" (`perfil.sons`) passa a valer. Desligada, silencia o toque da Central flutuante, o bipe de entrada e saída da presença no cabeçalho e o som do calendário do Dashboard. Ligada, continuam valendo as opções de som de cada um.
- Profile: função Twig `perfil_sons()` (padrão ligado, por usuário e escritório, memória por requisição) e atributo `data-perfil-sons` no elemento raiz de cada um dos três componentes.
- Meu Perfil: ponto de inclusão do selo de apoiador do Contribua (`contribua/_selo_apoiador.html.twig`, opcional).

## Para o cliente
- Em Meu Perfil, desligar "Sons de alerta" deixa o sistema em silêncio: sem toque de mensagem, sem aviso sonoro de quem entrou ou saiu e sem o som do calendário.
