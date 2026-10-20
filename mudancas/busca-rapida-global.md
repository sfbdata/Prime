---
tipo: novidade
---
## Changelog
- Busca rápida (Ctrl + K) em toda página logada, não só na pasta: a janela, o CSS e o JS entram pelo parcial global `layout/_globais/busca_rapida.html.twig` (para quem tem o módulo Pastas e um escritório atual) e saíram do `pasta/show.html.twig`. Abrir leva para a pasta; pasta trancada continua com o aviso de sigilo e o "Pedir acesso".
- Única exceção: com o foco na descrição ou no comentário do cartão do Kanban (Quill com botão de link, que usa Ctrl + K para inserir link), o atalho fica com o editor.

## Para o cliente
- Em qualquer tela do sistema, aperte Ctrl + K para buscar uma pasta pelo número, pelo nome ou CPF do cliente ou pelo número do processo, e abrir com Enter.
