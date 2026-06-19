<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Unit;

use App\Entity\Notificacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Notificacao::class)]
final class NotificacaoCategoriaTest extends TestCase
{
    #[TestDox('getCategoria deriva a categoria correta a partir do tipo')]
    #[DataProvider('tiposProvider')]
    public function testCategoriaDerivadaDoTipo(string $tipo, string $esperado): void
    {
        $notificacao = (new Notificacao())->setTipo($tipo);

        self::assertSame($esperado, $notificacao->getCategoria());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tiposProvider(): array
    {
        return [
            'ponto enviada → gestão'          => [Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA, Notificacao::CATEGORIA_GESTAO],
            'chamado atribuído → gestão'      => [Notificacao::TIPO_SERVICEDESK_ATRIBUICAO, Notificacao::CATEGORIA_GESTAO],
            'tarefa criada → pessoal'         => [Notificacao::TIPO_TAREFA_CRIADA, Notificacao::CATEGORIA_PESSOAL],
            'ponto abonada → pessoal'         => [Notificacao::TIPO_PONTO_JUSTIFICATIVA_APROVADA, Notificacao::CATEGORIA_PESSOAL],
            'ponto rejeitada → pessoal'       => [Notificacao::TIPO_PONTO_JUSTIFICATIVA_REJEITADA, Notificacao::CATEGORIA_PESSOAL],
            'evento → pessoal'                => [Notificacao::TIPO_EVENTO_CRIADO, Notificacao::CATEGORIA_PESSOAL],
            'servicedesk comum → pessoal'     => ['servicedesk', Notificacao::CATEGORIA_PESSOAL],
        ];
    }
}
