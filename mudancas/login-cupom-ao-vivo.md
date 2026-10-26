---
tipo: novidade
---
## Changelog
- Login: no "Criar conta", o cupom de indicação é conferido enquanto se digita ("Cupom válido" ou "Cupom não encontrado"), sem mostrar quem indicou.
- Novo endpoint público `POST /cadastro/cupom/conferir`, limitado a 20 consultas por 15 minutos por IP (`indicacao_cupom`).

## Para o cliente
- Ao digitar o cupom de indicação no cadastro, a tela já avisa se o cupom é válido.
