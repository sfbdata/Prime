---
tipo: correcao
---
## Changelog
- Campanha BlueJus Black, aba do topo (`contribua-campanha.css/js`): volta à posição do desenho (`right:24px`, sem o centro do #851), com o topo na base da barra azul. `baseDoCabecalho()` passa a achar o `#mainHeader` (`nav.app-header`), que o seletor do desenho (`header`, `[role=banner]`) não achava: a aba caía em `top:0`, por cima do nome e do avatar. Continua sumindo em 5 s; regra dos 5 dias e prévia do Master sem mudança.

## Para o cliente
- A aba da campanha BlueJus Black volta para o canto direito, pendurada logo abaixo da barra azul do topo, sem cobrir o seu nome nem a sua foto. Ela continua sumindo sozinha depois de alguns segundos.
