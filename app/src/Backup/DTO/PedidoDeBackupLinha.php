<?php

declare(strict_types=1);

namespace App\Backup\DTO;

/**
 * Uma linha da lista "Backup e Exportação de Dados" do Meu Perfil. `situacao` é a situação que a pessoa vê: um pedido
 * pronto que já passou da validade aparece como `vencido` mesmo antes de a tarefa agendada apagar o arquivo, e `pedido`
 * (na fila) e `montando` aparecem juntos como `em_preparo`.
 */
final readonly class PedidoDeBackupLinha
{
    public function __construct(
        public int $id,
        public string $situacao,
        public \DateTimeImmutable $pedidoEm,
        public ?int $tamanhoBytes,
        public ?\DateTimeImmutable $validoAte,
        public bool $podeBaixar,
    ) {
    }
}
