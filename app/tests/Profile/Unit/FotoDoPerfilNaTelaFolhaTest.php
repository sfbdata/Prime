<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trocar a foto no Meu Perfil: o script procurava `.perfil-foto-btn`, que saiu da página com o avatar do desenho 07
 * (`.mp-avatar > #fotoPerfilAtual`). O `null` estourava dentro do try e a tela mostrava "Erro de comunicação." com a
 * foto já salva, sem fechar o recorte. O comportamento na tela é conferido no navegador.
 */
#[CoversNothing]
final class FotoDoPerfilNaTelaFolhaTest extends TestCase
{
    #[TestDox('Depois de salvar, o recorte fecha e a foto troca no avatar de hoje; só a rede vira "Erro de comunicação."')]
    public function testFotoNovaNoAvatarDoDesenho(): void
    {
        $tela = (string) file_get_contents(__DIR__ . '/../../../templates/profile/index.html.twig');
        $script = substr($tela, (int) strpos($tela, "btnConfirmar.addEventListener('click'"));

        self::assertStringNotContainsString("querySelector('.perfil-foto-btn')", $tela, 'o seletor antigo não existe mais na página');
        self::assertStringContainsString("const atual = document.getElementById('fotoPerfilAtual');", $script);
        self::assertStringContainsString('atual.replaceWith(imgEl);', $script, 'as iniciais viram a imagem');
        self::assertMatchesRegularExpression('/data = await res\.json\(\);\s*\} catch \{\s*data = null;/', $script, 'o try cobre só a rede e a resposta');
        self::assertMatchesRegularExpression('/fecharCropper\(\);\s*mostrarFotoNova\(/', $script, 'o recorte fecha antes de mexer na tela');
    }
}
