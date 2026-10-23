---
tipo: novidade
---
## Changelog
- Pasta, menu ⋮ do cabeçalho: "Mover para outra carteira", como no desenho 02 VS 18.10.26. O submenu "Mover pasta N para" lista as seis carteiras (Contencioso cível, Condominial, Família e sucessões, Trabalhista, Previdenciário, Empresarial), marca a atual com "· atual" e grava pela rota `pasta_mover_carteira` (POST, CSRF, permissão de editar a pasta). O Registro ganha o cartão "Carteira: movida de "X" para "Y"." e a Timeline mostra "Carteira alterada".
- Migration `Version20261020151500`: coluna nula `pasta.carteira`. As pastas existentes ficam "Sem carteira"; nada é preenchido para trás.

## Para o cliente
- Agora dá para mover uma pasta para outra carteira (área do direito) pelo menu ⋮ da pasta. A mudança fica registrada no histórico.
