<?php

declare(strict_types=1);

namespace App\Markdown\Extension\YouTube\Renderer\Block;

use App\Markdown\Extension\YouTube\Node\Block\YouTubeEmbedNode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class YouTubeEmbedRenderer implements NodeRendererInterface
{
    /**
     * @param  YouTubeEmbedNode  $node
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        YouTubeEmbedNode::assertInstanceOf($node);

        $videoId = $node->videoId;
        $embedUrl = 'https://www.youtube-nocookie.com/embed/'.$videoId.'?autoplay=1';
        $watchUrl = 'https://www.youtube.com/watch?v='.$videoId;

        // Render a lite YouTube facade that only loads the iframe when clicked. Nothing, not even a thumbnail, is
        // requested from YouTube before that click, so visitors' IPs stay with us until they choose to play. The label
        // keeps the div non-empty, which HTML purification would otherwise remove, and links to the video wherever
        // resources/js/youtubeLite.js (the click-to-play behavior) does not run, such as API consumers.
        return '<div class="youtube-lite" data-video-id="'.$videoId.'" data-embed-url="'.$embedUrl.'">'.
            '<a href="'.$watchUrl.'">Play YouTube video</a>'.
            '</div>';
    }
}
