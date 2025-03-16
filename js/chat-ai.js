(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.chatAI = {
    attach: function (context, settings) {
      $(".block--chat-ai-floating", context).hide();

      $(".chat-ai--icon").on("click", function () {
        $(".block--chat-ai-floating", context).toggle();
      });

      $(".chat-ai--close-icon").on("click", function () {
        $(".block--chat-ai-floating", context).hide();
      });

      $("#edit-chat-container").on("DOMSubtreeModified", function () {
        $(".edit-chat-container").animate(
          { scrollTop: $(".edit-chat-container").get(0).scrollHeight },
          100
        );
        console.log("Scroll");
      });
    },
  };
})(jQuery, Drupal, drupalSettings);
