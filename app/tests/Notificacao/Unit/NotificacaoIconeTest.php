<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Unit;

use App\Entity\Notificacao;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * FPN-036: o ícone e a cor de cada tipo de notificação na lista do sino seguem o mapa `TIPOS` do
 * desenho (bluejus-central.js L.62-77). Os dois tipos novos (alerta de meta e análise de IA
 * concluída) caíam no `default` cinza.
 */
#[CoversClass(Notificacao::class)]
#[Group('notificacao')]
final class NotificacaoIconeTest extends TestCase
{
    private function icone(string $tipo): string
    {
        return (new Notificacao())->setTipo($tipo)->getIcone();
    }

    #[TestDox('Alerta para verificar a meta: sino cheio em laranja (desenho: `alerta`, bi-bell-fill #e8590c)')]
    public function testAlertaDeMetaEhSinoLaranja(): void
    {
        self::assertSame('tarefa_alerta_verificar', AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO, 'o tipo gravado no banco não muda');
        self::assertSame('bi-bell-fill text-orange', $this->icone(AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO));
    }

    #[TestDox('Análise de IA concluída: documento analisado em azul (desenho: `documento_analisado`, bi-file-earmark-check)')]
    public function testAnaliseDeIaEhDocumentoAnalisado(): void
    {
        self::assertSame('ia_analise_concluida', ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO, 'o tipo gravado no banco não muda');
        self::assertSame('bi-file-earmark-check text-primary', $this->icone(ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO));
    }

    /** @return iterable<string, array{string, string}> */
    public static function tiposQueJaExistiam(): iterable
    {
        yield 'tarefa criada' => [Notificacao::TIPO_TAREFA_CRIADA, 'bi-plus-circle text-primary'];
        yield 'publicação do Push' => [Notificacao::TIPO_DJEN_PUBLICACAO, 'bi-newspaper text-primary'];
        yield 'resposta no Registro' => [Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO, 'bi-person-check text-warning'];
        yield 'menção no Registro' => [Notificacao::TIPO_PASTA_MENCAO_REGISTRO, 'bi-at text-primary'];
        yield 'tipo desconhecido' => ['tipo_que_nao_existe', 'bi-bell text-secondary'];
    }

    #[DataProvider('tiposQueJaExistiam')]
    #[TestDox('Os braços que já existiam não mudam: $tipo')]
    public function testBracosAnterioresIntactos(string $tipo, string $esperado): void
    {
        self::assertSame($esperado, $this->icone($tipo));
    }

    #[TestDox('A classe text-orange do sino de alerta tem cor no app.css (o Bootstrap não a define), com par no tema escuro')]
    public function testTextOrangeTemCorNosDoisTemas(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3) . '/public/css/app.css');
        self::assertMatchesRegularExpression('/^\.notif-icon\.text-orange \{ color: #e8590c; \}$/m', $css);
        self::assertMatchesRegularExpression('/^html\[data-bs-theme="dark"\] \.notif-icon\.text-orange \{ color: #[0-9a-f]{6}; \}$/m', $css);
        self::assertGreaterThan(strpos($css, '.notif-icon  {'), strpos($css, '.notif-icon.text-orange'), 'depois do .notif-icon, para vencer a cor padrão');
    }
}
