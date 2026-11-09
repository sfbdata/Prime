---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, etapa E1 do desenho 16 (VS 08.11.26): a /ponto ganha a grade de duas colunas e o cartão "Registrar Ponto" com os componentes e os valores do desenho (CSS próprio `ponto-eletronico.css`, prefixo `pe-`), sobre o motor de hoje. Tipos em botões de rádio com a hora de cada batida (P6, P8); tipo já batido travado e dia completo com o botão cinza "Jornada de hoje concluída" (P7, decisão do dono de 09/11/2026); relógio com a hora do servidor; situação do dia; aviso do intervalo ligado à chave da Jornada do escritório; faixa da pendência do escritório atual; saída prevista pela escala. Sem migration.
- A spec docs/specs/ponto-desenho-0811.md passa a trazer as decisões de 09/11/2026 (P1 a P96, D1 a D22), as etapas ajustadas e as pendências do dono (relógio da VPS, segredo do comprovante, fornecedor da biometria). As specs ponto-batida-que-responde-e-conta-certa e ponto-folha-uma-batida-por-tipo ganham nota do P7.

## Para o cliente
- A tela do Ponto Eletrônico está de cara nova: o cartão de registro mostra os quatro tipos de batida com o horário de cada um, a situação do seu dia ("Em jornada", "Em repouso"...) e a saída prevista.
- O tipo que você já bateu hoje fica marcado e não aceita outro clique; com as quatro batidas feitas, o botão avisa que a jornada de hoje está concluída.
